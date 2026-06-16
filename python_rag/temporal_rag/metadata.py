"""PostgreSQL metadata updates for Temporal ingestion activities."""

from __future__ import annotations

from contextlib import contextmanager
import json
import logging
import mimetypes
from pathlib import Path
from typing import Any, Iterator
from uuid import NAMESPACE_URL, uuid5

from temporal_rag.settings import TemporalRagSettings

logger = logging.getLogger(__name__)


class AppMetadataStore:
    """Narrow Postgres writer for Laravel-owned ingestion metadata."""

    def __init__(self, settings: TemporalRagSettings) -> None:
        self.settings = settings

    @contextmanager
    def connection(self) -> Iterator[Any]:
        try:
            import psycopg
        except ModuleNotFoundError as exc:
            raise RuntimeError("psycopg is required for PostgreSQL metadata updates.") from exc

        conn = psycopg.connect(
            host=self.settings.db_host,
            port=self.settings.db_port,
            dbname=self.settings.db_name,
            user=self.settings.db_user,
            password=self.settings.db_password,
            autocommit=True,
        )
        try:
            yield conn
        finally:
            conn.close()

    def mark_phase(self, workflow_input: dict[str, Any], phase: str, status: str, details: dict[str, Any] | None = None) -> None:
        details = details or {}
        source_id = str(workflow_input.get("source_id") or "")
        job_id = str(workflow_input.get("job_id") or "")
        if not source_id:
            return

        try:
            with self.connection() as conn:
                with conn.cursor() as cur:
                    cur.execute(
                        """
                        update ingestion_sources
                           set index_status = %s,
                               metadata = (coalesce(metadata::jsonb, '{}'::jsonb) || %s::jsonb)::json,
                               updated_at = now()
                         where source_id = %s
                        """,
                        (
                            self._source_status(status),
                            json.dumps(self._source_metadata(workflow_input, phase, status, details)),
                            source_id,
                        ),
                    )

                    if job_id:
                        cur.execute(
                            """
                            update pipeline_jobs
                               set status = %s,
                                   current_stage = %s,
                                   index_status = %s,
                                   error_message = %s,
                                   metadata = %s::json,
                                   updated_at = now()
                             where job_id = %s
                            """,
                            (
                                self._job_status(status),
                                phase,
                                self._source_status(status),
                                details.get("error") or details.get("error_details"),
                                json.dumps(details),
                                job_id,
                            ),
                        )
        except Exception:
            logger.exception("app_metadata:mark_phase failed source_id=%s phase=%s", source_id, phase)

    def mark_ready(self, workflow_input: dict[str, Any], result: dict[str, Any]) -> None:
        source_id = str(workflow_input.get("source_id") or "")
        job_id = str(workflow_input.get("job_id") or "")
        if not source_id:
            return

        try:
            with self.connection() as conn:
                with conn.cursor() as cur:
                    cur.execute(
                        """
                        update ingestion_sources
                           set index_status = 'ready',
                               ready_at = now(),
                               last_scraped_at = coalesce(last_scraped_at, now()),
                               document_version = %s,
                               metadata = (coalesce(metadata::jsonb, '{}'::jsonb) || %s::jsonb)::json,
                               updated_at = now()
                         where source_id = %s
                        """,
                        (
                            result.get("document_version"),
                            json.dumps(self._source_metadata(workflow_input, "mark_source_ready", "ready", result)),
                            source_id,
                        ),
                    )
                    if job_id:
                        cur.execute(
                            """
                            update pipeline_jobs
                               set status = 'completed',
                                   current_stage = 'mark_source_ready',
                                   index_status = 'ready',
                                   total_documents = %s,
                                   processed_documents = %s,
                                   failed_documents = %s,
                                   skipped_documents = %s,
                                   completed_at = now(),
                                   finished_at = now(),
                                   metadata = %s::json,
                                   updated_at = now()
                             where job_id = %s
                            """,
                            (
                                int(result.get("documents_indexed") or 0),
                                int(result.get("documents_indexed") or 0),
                                int(result.get("failed_documents") or 0),
                                int(result.get("skipped_documents") or 0),
                                json.dumps(result),
                                job_id,
                            ),
                        )
        except Exception:
            logger.exception("app_metadata:mark_ready failed source_id=%s", source_id)

    def upsert_documents(
        self,
        workflow_input: dict[str, Any],
        records: list[dict[str, Any]],
        bridge_response: dict[str, Any],
    ) -> None:
        if not records:
            return

        ingestion = workflow_input.get("ingestion") if isinstance(workflow_input.get("ingestion"), dict) else {}
        dataset_id = str(workflow_input.get("dataset_id") or "default")
        collection = str(ingestion.get("collection") or workflow_input.get("qdrant_collection") or dataset_id)
        neo4j_namespace = str(ingestion.get("neo4j_namespace") or collection)
        source_id = str(workflow_input.get("source_id") or "")
        job_id = str(workflow_input.get("job_id") or "")
        task_id = str(workflow_input.get("task_id") or "")
        source_url = str(workflow_input.get("source_url") or "")
        source_type = "upload" if source_url.startswith("upload://") else "scrape"
        upload = workflow_input.get("upload") if isinstance(workflow_input.get("upload"), dict) else {}
        upload_filename = str(upload.get("original_filename") or "")
        if not upload_filename and source_url.startswith("upload://"):
            upload_filename = source_url.removeprefix("upload://")

        try:
            with self.connection() as conn:
                with conn.cursor() as cur:
                    for record in records:
                        checksum = str(record.get("content_hash") or "")
                        if not checksum:
                            continue

                        passthrough = record.get("passthrough") if isinstance(record.get("passthrough"), dict) else {}
                        markdown_path = str(record.get("markdown_path") or "")
                        relative_path = str(record.get("relative_path") or "")
                        original_path = str(passthrough.get("original_path") or passthrough.get("file_path") or "")
                        original_filename = str(
                            upload_filename
                            or passthrough.get("original_filename")
                            or Path(relative_path or markdown_path).name
                            or ""
                        )
                        storage_path = original_path or markdown_path or relative_path
                        title_source = original_filename or relative_path or storage_path
                        title = Path(title_source).stem or str(record.get("document_id") or "Document")
                        document_id = str(record.get("document_id") or "")
                        metadata = {
                            "source_id": source_id,
                            "task_id": task_id,
                            "job_id": job_id,
                            "document_id": document_id,
                            "relative_path": relative_path,
                            "qdrant_collection": collection,
                            "neo4j_namespace": neo4j_namespace,
                            "bridge_response": bridge_response,
                        }
                        if passthrough:
                            metadata["passthrough"] = passthrough

                        cur.execute(
                            """
                            insert into documents (
                                id,
                                external_id,
                                dataset_id,
                                collection,
                                source_type,
                                source_url,
                                original_filename,
                                storage_path,
                                mime_type,
                                file_size,
                                checksum_sha256,
                                title,
                                metadata_json,
                                status,
                                created_at,
                                updated_at
                            )
                            values (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s::json, 'completed', now(), now())
                            on conflict (collection, checksum_sha256) do update
                               set external_id = excluded.external_id,
                                   dataset_id = excluded.dataset_id,
                                   source_type = excluded.source_type,
                                   source_url = excluded.source_url,
                                   original_filename = excluded.original_filename,
                                   storage_path = excluded.storage_path,
                                   mime_type = excluded.mime_type,
                                   file_size = excluded.file_size,
                                   title = excluded.title,
                                   metadata_json = excluded.metadata_json,
                                   status = 'completed',
                                   updated_at = now()
                            """,
                            (
                                str(uuid5(NAMESPACE_URL, f"{collection}:{checksum}")),
                                document_id or job_id or source_id or None,
                                dataset_id,
                                collection,
                                source_type,
                                source_url or None,
                                original_filename or None,
                                storage_path,
                                self._mime_type(original_filename, bool(passthrough)),
                                self._file_size(storage_path),
                                checksum,
                                title,
                                json.dumps(metadata),
                            ),
                        )
        except Exception:
            logger.exception("app_metadata:upsert_documents failed source_id=%s records=%s", source_id, len(records))

    def mark_failed(self, workflow_input: dict[str, Any], phase: str, error: str) -> None:
        details = {"status": "failed", "phase": phase, "error": error}
        self.mark_phase(workflow_input, phase, "failed", details)

    @staticmethod
    def _file_size(path: str) -> int | None:
        if not path or path.startswith("s3://"):
            return None
        try:
            return Path(path.removeprefix("file://")).expanduser().stat().st_size
        except OSError:
            return None

    @staticmethod
    def _mime_type(filename: str, is_passthrough: bool) -> str:
        if is_passthrough:
            guessed_type, _ = mimetypes.guess_type(filename)
            return guessed_type or "application/octet-stream"
        return "text/markdown"

    @staticmethod
    def _source_status(status: str) -> str:
        return {
            "started": "running",
            "running": "running",
            "success": "running",
            "completed": "running",
            "ready": "ready",
            "failed": "failed",
            "skipped": "failed",
            "timeout": "failed",
            "cancelled": "cancelled",
            "canceled": "cancelled",
        }.get(status, status or "running")

    @staticmethod
    def _job_status(status: str) -> str:
        return {
            "started": "running",
            "running": "running",
            "success": "running",
            "completed": "running",
            "ready": "completed",
            "failed": "failed",
            "skipped": "skipped",
            "timeout": "failed",
            "cancelled": "failed",
            "canceled": "failed",
        }.get(status, "running")

    @staticmethod
    def _source_metadata(
        workflow_input: dict[str, Any],
        phase: str,
        status: str,
        details: dict[str, Any],
    ) -> dict[str, Any]:
        return {
            "temporal": {
                "workflow_type": "IngestSourceWorkflow",
                "phase": phase,
                "status": status,
            },
            "source_id": workflow_input.get("source_id"),
            "source_url": workflow_input.get("source_url"),
            "task_id": workflow_input.get("task_id"),
            "job_id": workflow_input.get("job_id"),
            "raw_output_path": workflow_input.get("raw_output_path"),
            "markdown_output_path": workflow_input.get("markdown_output_path"),
            "details": details,
        }


__all__ = ["AppMetadataStore"]
