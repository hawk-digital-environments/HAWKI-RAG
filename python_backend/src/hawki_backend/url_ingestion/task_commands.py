"""Task commands compose URL generations; their projection is never an authority.

One nullable intent on the original URL request freezes membership before I/O.
Its request id gives every source command a deterministic identity, including
headerless retries and retries by another authorized user. Dataset admission
locks serialize intent with recrawl/delete; pending intents reserve their sources.
"""

from datetime import datetime, timedelta, timezone
from typing import TYPE_CHECKING

import sqlalchemy as sa

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.persistence.datasets import (
    lock_active_dataset,
    DatasetInactiveError,
    DatasetNotFoundError,
)

from .errors import UrlIngestionError
from .locking import one
from .repository import require_grant
from .schema import requests, generations, sources


if TYPE_CHECKING:
    from .service import UrlIngestionService


def accept(
    service: "UrlIngestionService",
    principal: AuthenticatedPrincipal,
    task_id: str,
    action: str,
) -> dict:
    repository = service.repository
    row, _ = repository.task(principal.tenant_id, task_id)
    service.authorize(principal, row["dataset_id"])
    with repository.engine.begin() as connection:
        try:
            lock_active_dataset(connection, principal.tenant_id, row["dataset_id"])
        except (DatasetInactiveError, DatasetNotFoundError):
            raise UrlIngestionError(
                404, "The requested dataset is not available."
            ) from None
        require_grant(connection, principal, row["dataset_id"])
        row = one(connection, requests, requests.c.request_id == row["request_id"])
        if row["purpose"] != "start":
            raise UrlIngestionError(409, "Only URL execution tasks accept commands.")
        previous = row["task_command"]
        if previous:
            if previous["action"] == action:
                return row
            if previous["state"] == "pending" or previous["action"] == "delete":
                raise UrlIngestionError(409, "Another task command owns this task.")
        children = connection.execute(
            sa.select(generations)
            .where(generations.c.request_id == row["request_id"])
            .order_by(generations.c.source_id)
        ).mappings()
        targets = []
        for child in children:
            source = one(connection, sources, sources.c.source_id == child["source_id"])
            if action == "cancel":
                # Completion records a workflow outcome, not remote drainage.
                # Never fence a successor on behalf of this historical task.
                if source["generation"] != child["generation"]:
                    if child["stage"] == "completed":
                        continue
                    raise UrlIngestionError(409, "URL generation was superseded.")
                if source["pending_operation_id"] not in {
                    None,
                    child["url_ingestion_operation_id"],
                }:
                    raise UrlIngestionError(
                        409,
                        "A successor generation must finish reconciling this execution first.",
                    )
            # Deletion may resume a source delete already accepted independently.
            # A later source generation is frozen now, before remote side effects.
            targets.append(
                {"source_id": source["source_id"], "generation": source["generation"]}
            )
        others = connection.execute(
            sa.select(requests.c.request_id, requests.c.task_command).where(
                requests.c.tenant_id == principal.tenant_id,
                requests.c.dataset_id == row["dataset_id"],
                requests.c.task_command["state"].as_string() == "pending",
                requests.c.request_id != row["request_id"],
            )
        ).mappings()
        source_ids = {target["source_id"] for target in targets}
        for other in others:
            command = other["task_command"]
            if command["state"] == "pending" and any(
                target["source_id"] in source_ids for target in command["targets"]
            ):
                raise UrlIngestionError(409, "Another task command owns this source.")
        intent = {
            "action": action,
            "state": "pending",
            "targets": targets,
            "principal_id": principal.principal_id,
            "principal_type": principal.kind.value,
        }
        connection.execute(
            requests.update()
            .where(requests.c.request_id == row["request_id"])
            .values(task_command=intent, updated_at=sa.func.now())
        )
        return dict(row, task_command=intent)


async def execute(
    service: "UrlIngestionService",
    principal: AuthenticatedPrincipal,
    task_id: str,
    action: str,
) -> tuple[int, dict]:
    row = accept(service, principal, task_id, action)
    intent = row["task_command"]
    if intent["state"] != "pending":
        return response(service, principal, row)
    # The accepted command's principal owns its deterministic child requests.
    # The caller above and this principal must both retain their exact grant.
    owner = AuthenticatedPrincipal(
        kind=PrincipalKind(intent.get("principal_type", "user")),
        subject=intent["principal_id"],
        tenant_id=row["tenant_id"],
        client_id=intent["principal_id"]
        if intent.get("principal_type") == "service"
        else "url-task-command",
        scopes=frozenset({"rag:ingest"}),
        issuer="internal:url-task-command",
        expires_at=datetime.now(timezone.utc) + timedelta(minutes=1),
    )
    service.authorize(owner, row["dataset_id"])
    pending = None
    for target in intent["targets"]:
        try:
            source = service.repository.source(row["tenant_id"], target["source_id"])
            if source["pending_operation_id"]:
                active = service.repository.generation(source["pending_operation_id"])
                if active["purpose"] == "start" and active["stage"] in {
                    "fenced",
                    "drained",
                    "cleaned",
                }:
                    # Finish already accepted retirement without dispatching a crawl.
                    await service.resume(active, retirement_only=True)
            if action == "delete":
                if source["status"] == "deleted":
                    continue
                if source["status"] == "deleting":
                    # Adopt the existing source protocol, never allocate a rival.
                    await service.resume(
                        service.repository.generation(source["pending_operation_id"])
                    )
                else:
                    await service.delete(
                        owner,
                        source["source_id"],
                        "task-delete:" + row["request_id"] + ":" + source["source_id"],
                        task_command_id=row["request_id"],
                        expected_generation=target["generation"],
                    )
            else:
                values = service._request(
                    owner,
                    {"dataset_id": row["dataset_id"], "source_id": source["source_id"]},
                    "task-cancel:" + row["request_id"] + ":" + source["source_id"],
                    "cancel",
                )
                _, children = service.repository.claim(
                    owner,
                    values,
                    [(source["original_url"], source["normalized_url"])],
                    lambda source, request: service._candidate(source, request, {}),
                    expected_generations={source["source_id"]: target["generation"]},
                    task_command_id=row["request_id"],
                )
                for child in children:
                    await service.resume(child)
        except UrlIngestionError as error:
            if pending is None or (pending.status == 202 and error.status != 202):
                pending = error
        except Exception:
            # Cleanup failure leaves the same per-source stage durable. Continue
            # independent children so their verified cleanup stays committed.
            pending = pending or UrlIngestionError(
                503, "Task cleanup requires reconciliation."
            )
    if pending:
        raise pending
    row = finish(service, row)
    return response(service, principal, row)


def finish(service: "UrlIngestionService", row: dict) -> dict:
    intent = row["task_command"]
    action = intent["action"]
    with service.repository.engine.begin() as connection:
        current = one(connection, requests, requests.c.request_id == row["request_id"])
        if current["task_command"]["action"] != action:
            raise UrlIngestionError(409, "Task command ownership changed.")
        if current["task_command"]["state"] != "pending":
            return current
        for target in intent["targets"]:
            source = one(
                connection, sources, sources.c.source_id == target["source_id"]
            )
            if source["status"] != ("deleted" if action == "delete" else "cancelled"):
                raise UrlIngestionError(
                    202, "Task command is awaiting source completion."
                )
        intent = dict(intent, state="deleted" if action == "delete" else "cancelled")
        connection.execute(
            requests.update()
            .where(requests.c.request_id == row["request_id"])
            .values(task_command=intent, updated_at=sa.func.now())
        )
    return dict(row, task_command=intent)


def response(
    service: "UrlIngestionService", principal: AuthenticatedPrincipal, row: dict
) -> tuple[int, dict]:
    if row["task_command"]["action"] == "delete":
        return 200, {"success": True, "task_id": row["task_id"], "deleted": True}
    return 200, service.task_payload(principal, row["task_id"])


async def reconcile(service: "UrlIngestionService") -> list[dict]:
    """Resume accepted task intents even when the caller never retries."""
    with service.repository.engine.connect() as connection:
        rows = list(
            connection.execute(
                sa.select(requests)
                .where(requests.c.task_command["state"].as_string() == "pending")
                .order_by(requests.c.created_at, requests.c.task_id)
            ).mappings()
        )
    result = []
    for row in rows:
        principal = AuthenticatedPrincipal(
            kind=PrincipalKind(row["task_command"].get("principal_type", "user")),
            subject=row["task_command"]["principal_id"],
            tenant_id=row["tenant_id"],
            client_id=row["task_command"]["principal_id"]
            if row["task_command"].get("principal_type") == "service"
            else "url-task-command",
            scopes=frozenset({"rag:ingest"}),
            issuer="internal:url-task-command",
            expires_at=datetime.now(timezone.utc) + timedelta(minutes=1),
        )
        try:
            await execute(
                service, principal, row["task_id"], row["task_command"]["action"]
            )
            state = "completed"
        except UrlIngestionError:
            state = "pending"
        result.append({"task_id": row["task_id"], "result": state})
    return result
