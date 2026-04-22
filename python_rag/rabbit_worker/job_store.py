from __future__ import annotations

import json
import sqlite3
import threading
from contextlib import closing
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Optional


STATUS_PROCESSING = "processing"
STATUS_RETRY_QUEUED = "retry_queued"
STATUS_COMPLETED = "completed"
STATUS_FAILED = "failed"


def _utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


@dataclass
class JobRecord:
    job_id: str
    status: str
    retry_count: int
    max_retries: int
    error_type: Optional[str] = None
    error_message: Optional[str] = None
    processing_stage: Optional[str] = None


class JobStore:
    """
    Simple SQLite idempotency store keyed by job_id.
    """

    def __init__(self, db_path: str):
        self.db_path = str(db_path)
        self._lock = threading.Lock()
        self._initialized = False

    def setup(self) -> None:
        path = Path(self.db_path)
        path.parent.mkdir(parents=True, exist_ok=True)
        with closing(self._connect()) as conn:
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS worker_job_tracking (
                    job_id TEXT PRIMARY KEY,
                    status TEXT NOT NULL,
                    retry_count INTEGER NOT NULL DEFAULT 0,
                    max_retries INTEGER NOT NULL DEFAULT 0,
                    error_type TEXT NULL,
                    error_message TEXT NULL,
                    processing_stage TEXT NULL,
                    last_payload_json TEXT NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )
                """
            )
            conn.execute(
                """
                CREATE INDEX IF NOT EXISTS idx_worker_job_tracking_status
                ON worker_job_tracking(status)
                """
            )
            conn.commit()
        self._initialized = True

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.db_path, timeout=30.0, check_same_thread=False)
        conn.row_factory = sqlite3.Row
        return conn

    def _ensure_setup(self) -> None:
        if not self._initialized:
            self.setup()

    def claim_for_processing(
        self,
        *,
        job_id: str,
        retry_count: int,
        max_retries: int,
        payload: Optional[Dict[str, Any]] = None,
    ) -> bool:
        """
        Returns False when the job is already completed (duplicate delivery).
        """
        self._ensure_setup()
        now = _utc_now()
        payload_json = json.dumps(payload, ensure_ascii=False) if payload is not None else None
        with self._lock:
            with closing(self._connect()) as conn:
                conn.execute("BEGIN IMMEDIATE")
                row = conn.execute(
                    "SELECT status FROM worker_job_tracking WHERE job_id = ?",
                    (job_id,),
                ).fetchone()
                if row and row["status"] == STATUS_COMPLETED:
                    conn.commit()
                    return False

                conn.execute(
                    """
                    INSERT INTO worker_job_tracking (
                        job_id, status, retry_count, max_retries, processing_stage, last_payload_json, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(job_id) DO UPDATE SET
                        status = excluded.status,
                        retry_count = excluded.retry_count,
                        max_retries = excluded.max_retries,
                        processing_stage = excluded.processing_stage,
                        last_payload_json = excluded.last_payload_json,
                        updated_at = excluded.updated_at
                    """,
                    (
                        job_id,
                        STATUS_PROCESSING,
                        int(retry_count),
                        int(max_retries),
                        "processing-start",
                        payload_json,
                        now,
                        now,
                    ),
                )
                conn.commit()
        return True

    def mark_completed(self, *, job_id: str, retry_count: int, max_retries: int) -> None:
        self._upsert_status(
            job_id=job_id,
            status=STATUS_COMPLETED,
            retry_count=retry_count,
            max_retries=max_retries,
            processing_stage="success",
        )

    def mark_retry_queued(
        self,
        *,
        job_id: str,
        retry_count: int,
        max_retries: int,
        error_type: str,
        error_message: str,
    ) -> None:
        self._upsert_status(
            job_id=job_id,
            status=STATUS_RETRY_QUEUED,
            retry_count=retry_count,
            max_retries=max_retries,
            error_type=error_type,
            error_message=error_message,
            processing_stage="retry-published",
        )

    def mark_failed(
        self,
        *,
        job_id: str,
        retry_count: int,
        max_retries: int,
        error_type: str,
        error_message: str,
    ) -> None:
        self._upsert_status(
            job_id=job_id,
            status=STATUS_FAILED,
            retry_count=retry_count,
            max_retries=max_retries,
            error_type=error_type,
            error_message=error_message,
            processing_stage="failed-published",
        )

    def get(self, job_id: str) -> Optional[JobRecord]:
        self._ensure_setup()
        with closing(self._connect()) as conn:
            row = conn.execute(
                """
                SELECT job_id, status, retry_count, max_retries, error_type, error_message, processing_stage
                FROM worker_job_tracking WHERE job_id = ?
                """,
                (job_id,),
            ).fetchone()
        if row is None:
            return None
        return JobRecord(
            job_id=row["job_id"],
            status=row["status"],
            retry_count=int(row["retry_count"]),
            max_retries=int(row["max_retries"]),
            error_type=row["error_type"],
            error_message=row["error_message"],
            processing_stage=row["processing_stage"],
        )

    def _upsert_status(
        self,
        *,
        job_id: str,
        status: str,
        retry_count: int,
        max_retries: int,
        processing_stage: str,
        error_type: Optional[str] = None,
        error_message: Optional[str] = None,
    ) -> None:
        self._ensure_setup()
        now = _utc_now()
        with self._lock:
            with closing(self._connect()) as conn:
                conn.execute(
                    """
                    INSERT INTO worker_job_tracking (
                        job_id, status, retry_count, max_retries, error_type, error_message, processing_stage, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(job_id) DO UPDATE SET
                        status = excluded.status,
                        retry_count = excluded.retry_count,
                        max_retries = excluded.max_retries,
                        error_type = excluded.error_type,
                        error_message = excluded.error_message,
                        processing_stage = excluded.processing_stage,
                        updated_at = excluded.updated_at
                    """,
                    (
                        job_id,
                        status,
                        int(retry_count),
                        int(max_retries),
                        error_type,
                        error_message,
                        processing_stage,
                        now,
                        now,
                    ),
                )
                conn.commit()
