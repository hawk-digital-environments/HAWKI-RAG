"""Recovery uses explicit Python URL generations, never Laravel job state."""

from typing import TYPE_CHECKING

import sqlalchemy as sa

from hawki_backend.auth.models import AuthenticatedPrincipal

from .errors import UrlIngestionError
from .identity import digest
from .schema import generations, requests, sources

if TYPE_CHECKING:
    from .service import UrlIngestionService


async def recover(
    service: "UrlIngestionService",
    principal: AuthenticatedPrincipal,
    path: str,
    body: dict,
    key: str | None,
) -> tuple[int, dict]:
    parts = path.split("/")
    filters = [
        generations.c.tenant_id == principal.tenant_id,
        generations.c.purpose == "start",
        sources.c.status.not_in(["deleted", "deleting"]),
    ]
    scope_id = None
    selected = None
    if path == "retry-all":
        scope = "all"
    elif path == "jobs/retry-selected":
        scope = "selected"
        selected = body.get("job_ids", body.get("jobIds", [])) or []
        if (
            not isinstance(selected, list)
            or len(selected) > 1000
            or any(
                not isinstance(value, str) or not value.strip() or len(value) > 191
                for value in selected
            )
        ):
            raise UrlIngestionError(422, "Invalid recovery job IDs.")
        selected = list(dict.fromkeys(value.strip() for value in selected))
        filters.append(generations.c.job_id.in_(selected))
    elif len(parts) == 3 and (parts[0], parts[2]) in {
        ("jobs", "retry"),
        ("tasks", "retry-failed"),
        ("datasets", "retry-failed"),
    }:
        scope = {"jobs": "job", "tasks": "task", "datasets": "dataset"}[parts[0]]
        scope_id = parts[1]
        filters.append(
            {
                "jobs": generations.c.job_id,
                "tasks": generations.c.task_id,
                "datasets": generations.c.dataset_id,
            }[parts[0]]
            == scope_id
        )
    else:
        raise UrlIngestionError(409, "Select an explicitly Python-owned URL operation.")
    if scope in {"all", "dataset"}:
        filters.append(sources.c.generation == generations.c.generation)
    with service.repository.engine.connect() as connection:
        rows = [
            dict(row)
            for row in connection.execute(
                sa.select(generations, requests.c.body.label("original_body"))
                .select_from(
                    generations.join(
                        sources,
                        sources.c.source_id == generations.c.source_id,
                    ).join(requests, requests.c.request_id == generations.c.request_id)
                )
                .where(*filters)
                .order_by(generations.c.source_id)
            ).mappings()
        ]
    if (selected is not None and {row["job_id"] for row in rows} != set(selected)) or (
        scope_id and not rows
    ):
        raise UrlIngestionError(404, "Python URL recovery operation was not found.")
    # Authorize the entire selected scope before any retry side effect.
    for dataset in {row["dataset_id"] for row in rows}:
        service.authorize(principal, dataset)
    result = dict(
        scope=scope,
        scopeId=scope_id,
        attempted=0,
        retried=0,
        skipped=0,
        failed=0,
        jobs=[],
        owner="url",
    )
    for row in rows:
        completion = row.get("completion") or {}
        if row["stage"] == "completed" and completion.get("status") not in {
            "failed",
            "skipped",
        }:
            result["skipped"] += 1
            continue
        if row["stage"] != "completed":
            await service.resume(row)
        else:
            await service.recrawl(
                principal,
                row["source_id"],
                {"metadata": row["original_body"]["metadata"]},
                digest([key or "recover", row["url_ingestion_operation_id"]]),
                expected_generation=row["generation"],
            )
        result["attempted"] += 1
        result["retried"] += 1
        result["jobs"].append(
            {
                "job_id": row["job_id"],
                "source_id": row["source_id"],
                "result": "retried",
            }
        )
    return 200, {"success": True, "recovery": result}


async def recover_managed(
    instance, principal, job_id: str, key: str | None
) -> tuple[int, dict]:
    """Retry via the existing managed replacement protocol using its frozen upload."""
    from pathlib import Path

    from hawki_backend.managed_documents.schema import revisions, mutations
    from hawki_backend.managed_documents.input import ManagedInput, ManagedError

    if instance is None:
        raise UrlIngestionError(503, "Managed document recovery is unavailable.")
    with instance.repository.engine.connect() as connection:
        row = (
            connection.execute(
                sa.select(revisions).where(
                    revisions.c.tenant_id == principal.tenant_id,
                    revisions.c.job_id == job_id,
                )
            )
            .mappings()
            .one_or_none()
        )
    if row is None:
        raise UrlIngestionError(404, "Managed job was not found.")
    doc = instance.repository.document(principal.tenant_id, row["managed_document_id"])
    instance.authorize(principal, doc["dataset_id"])
    recovery_key = digest(["pipeline-recovery", row["revision_id"], key or "retry"])
    mutation_id = digest(
        [
            principal.tenant_id,
            principal.principal_id,
            "update",
            row["managed_document_id"],
            recovery_key,
        ]
    )
    with instance.repository.engine.connect() as connection:
        previous = (
            connection.execute(
                sa.select(mutations).where(mutations.c.mutation_id == mutation_id)
            )
            .mappings()
            .one_or_none()
        )
    if previous is not None:
        status, payload = await instance.resume(dict(previous))
    else:
        if (
            doc["status"] in {"deleting", "deleted"}
            or (doc["pending_revision_id"] or doc["current_revision_id"])
            != row["revision_id"]
        ):
            raise UrlIngestionError(
                409, "Managed job generation was superseded or deleted."
            )
        if row["status"] != "failed":
            return 200, {
                "success": True,
                "recovery": {
                    "attempted": 0,
                    "retried": 0,
                    "failed": 0,
                    "skipped": 1,
                    "jobs": [{"job_id": job_id, "result": "skipped"}],
                },
            }
        original = instance.repository.mutation(row["mutation_id"])
        canonical = original["request"].get("canonical", original["request"])
        body = ManagedInput.model_validate(
            canonical
            | {
                "force": True,
                "source_checksum_sha256": row["source_checksum"],
            }
        )
        path = Path(row["upload_path"])
        if (
            not path.resolve().is_relative_to(instance.storage.root.resolve())
            or not path.is_file()
        ):
            raise ManagedError(
                409, "Managed upload is unavailable; upload the document again."
            )
        with path.open("rb") as stream:
            status, payload = await instance.mutate(
                principal,
                "update",
                body,
                doc["attributes"]["original_filename"],
                stream,
                recovery_key,
                row["managed_document_id"],
                expected_generation=doc["generation"],
                expected_revision_id=row["revision_id"],
            )
    if status >= 400 or payload.get("success") is not True:
        raise UrlIngestionError(
            status if status >= 400 else 503,
            "Managed recovery is unconfirmed; retry the same request.",
        )
    return 200, {
        "success": True,
        "recovery": {
            "attempted": 1,
            "retried": 1,
            "failed": 0,
            "skipped": 0,
            "jobs": [{"job_id": job_id, "owner": "managed", "result": "retried"}],
        },
    }
