"""PostgreSQL metadata updates for Temporal ingestion activities."""

from __future__ import annotations

from contextlib import contextmanager
import json
import logging
from typing import Any, Iterator

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
                               metadata = %s::json,
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
                               metadata = %s::json,
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

    def mark_failed(self, workflow_input: dict[str, Any], phase: str, error: str) -> None:
        details = {"status": "failed", "phase": phase, "error": error}
        self.mark_phase(workflow_input, phase, "failed", details)

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
