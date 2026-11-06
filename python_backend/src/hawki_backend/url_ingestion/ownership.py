"""Durable dispatch and source evidence; an absent owner is never legacy proof."""

import sqlalchemy as sa

from hawki_backend.workflow_control.schema import operations
from hawki_backend.managed_documents.schema import revisions
from hawki_backend.managed_documents.repository import ManagedRepository
from hawki_backend.managed_documents.input import ManagedError
from hawki_backend.text_ingestion.schema import ingest_text_operations

from .errors import UrlIngestionError
from .schema import generations, requests


FIELDS = frozenset({"task_id", "job_id", "workflow_id", "dataset_id"})


def classify(repository, body: dict) -> str:
    fields = [field for field in FIELDS if field in body]
    if len(fields) != 1:
        raise UrlIngestionError(422, "Supply one ownership identity.")
    field = fields[0]
    value = body[field]
    if not isinstance(value, str) or not value or len(value) > 255:
        raise UrlIngestionError(422, "Invalid ownership identity.")
    with repository.engine.connect() as connection:
        rows = list(
            connection.execute(
                sa.select(generations).where(generations.c[field] == value)
            ).mappings()
        )
        controls = list(
            connection.execute(
                sa.select(operations).where(
                    operations.c.action.in_(["source", "text"]),
                    (
                        operations.c.workflow_id == value
                        if field == "workflow_id"
                        else operations.c.payload["workflow_input"][field].as_string()
                        == value
                    ),
                )
            ).mappings()
        )
        owners = set()
        managed = list(
            connection.execute(
                sa.select(revisions).where(revisions.c[field] == value)
            ).mappings()
        )
        text = list(
            connection.execute(
                sa.select(ingest_text_operations).where(
                    ingest_text_operations.c[field] == value
                )
            ).mappings()
        )
        if managed:
            owners.add("managed")
        if text:
            owners.add("text")
        for row in rows:
            wire = row["workflow_input"]
            if row["purpose"] == "delete":
                owners.add("url")
                continue
            if (
                wire.get("owner") != "url"
                or wire.get("managed_document_id")
                or any(
                    wire.get(key) != row[key]
                    for key in (
                        "url_ingestion_operation_id",
                        "source_id",
                        "dataset_id",
                        "generation",
                        "job_id",
                        "task_id",
                    )
                )
            ):
                raise UrlIngestionError(409, "URL generation owner conflicts.")
            for control in controls:
                if control["workflow_id"] == row["workflow_id"]:
                    repository.owner(connection, row["workflow_id"])
            owners.add("url")
        for control in controls:
            wire = control["payload"]["workflow_input"]
            if wire.get("owner") == "url" or wire.get("url_ingestion_operation_id"):
                repository.owner(connection, control["workflow_id"])
                if wire.get("managed_document_id"):
                    raise UrlIngestionError(409, "Competing URL and managed owners.")
                owners.add("url")
            elif wire.get("owner") is not None:
                raise UrlIngestionError(409, "Unknown workflow owner.")
            elif control["action"] == "text":
                matching = [
                    row for row in text if row["workflow_id"] == control["workflow_id"]
                ]
                if (
                    matching
                    and matching[0]["request_metadata"].get("workflow_input") != wire
                ):
                    raise UrlIngestionError(409, "Text operation ownership conflicts.")
                owners.add("text" if matching else "laravel")
            elif wire.get("managed_document_id"):
                matching = [
                    row
                    for row in managed
                    if row["workflow_id"] == control["workflow_id"]
                ]
                if matching:
                    try:
                        ManagedRepository(repository.engine).owner(
                            connection, control["workflow_id"]
                        )
                    except ManagedError as error:
                        raise UrlIngestionError(
                            409, "Managed operation ownership conflicts."
                        ) from error
                owners.add("managed" if matching else "laravel")
            else:
                # Requires matching Laravel task/job/source evidence at the BFF.
                owners.add("laravel")
        if (
            field == "task_id"
            and connection.execute(
                sa.select(requests.c.request_id).where(requests.c.task_id == value)
            ).first()
        ):
            owners.add("url")
        if len(owners) > 1:
            raise UrlIngestionError(409, "Operation ownership is mixed or conflicting.")
        if not owners:
            return "absent"
        return owners.pop()
