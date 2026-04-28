from __future__ import annotations

import sqlite3
import threading
from contextlib import closing
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional


STATUS_RECEIVED = "received"
STATUS_PROCESSING = "processing"
STATUS_COMPLETED = "completed"
STATUS_FAILED = "failed"


def _utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


@dataclass
class JobStateRecord:
    job_id: str
    stage: str
    status: str
    retry_count: int
    max_retries: int
    input_path: Optional[str]
    output_path: Optional[str]
    error_type: Optional[str]
    error_message: Optional[str]
    trace_id: Optional[str]


class JobStateStore:
    """
    Local idempotency/job tracking store for worker runtime.
    Mirrors `job_processing_state` schema required by the pipeline contract.
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
                CREATE TABLE IF NOT EXISTS job_processing_state (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    job_id TEXT NOT NULL,
                    stage TEXT NOT NULL,
                    source TEXT NOT NULL,
                    input_path TEXT NULL,
                    output_path TEXT NULL,
                    input_checksum TEXT NULL,
                    status TEXT NOT NULL,
                    retry_count INTEGER NOT NULL DEFAULT 0,
                    max_retries INTEGER NOT NULL DEFAULT 3,
                    first_received_at TEXT NULL,
                    last_received_at TEXT NULL,
                    processing_started_at TEXT NULL,
                    completed_at TEXT NULL,
                    failed_at TEXT NULL,
                    error_type TEXT NULL,
                    error_message TEXT NULL,
                    trace_id TEXT NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    UNIQUE(job_id, stage)
                )
                """
            )
            conn.execute(
                """
                CREATE INDEX IF NOT EXISTS idx_job_processing_state_status
                ON job_processing_state(status)
                """
            )
            conn.execute(
                """
                CREATE INDEX IF NOT EXISTS idx_job_processing_state_stage
                ON job_processing_state(stage)
                """
            )
            conn.execute(
                """
                CREATE INDEX IF NOT EXISTS idx_job_processing_state_input_checksum
                ON job_processing_state(input_checksum)
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
        stage: str,
        source: str,
        input_path: str | None,
        output_path: str | None,
        input_checksum: str | None,
        retry_count: int,
        max_retries: int,
        trace_id: str | None,
    ) -> bool:
        """
        Returns False when `(job_id, stage)` is already completed.
        """
        self._ensure_setup()
        now = _utc_now()
        with self._lock:
            with closing(self._connect()) as conn:
                conn.execute("BEGIN IMMEDIATE")
                row = conn.execute(
                    "SELECT status, first_received_at FROM job_processing_state WHERE job_id = ? AND stage = ?",
                    (job_id, stage),
                ).fetchone()
                if row and row["status"] == STATUS_COMPLETED:
                    conn.execute(
                        """
                        UPDATE job_processing_state
                        SET last_received_at = ?, updated_at = ?
                        WHERE job_id = ? AND stage = ?
                        """,
                        (now, now, job_id, stage),
                    )
                    conn.commit()
                    return False

                first_received = row["first_received_at"] if row and row["first_received_at"] else now
                conn.execute(
                    """
                    INSERT INTO job_processing_state (
                        job_id, stage, source, input_path, output_path, input_checksum, status,
                        retry_count, max_retries, first_received_at, last_received_at,
                        processing_started_at, trace_id, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(job_id, stage) DO UPDATE SET
                        source = excluded.source,
                        input_path = excluded.input_path,
                        output_path = excluded.output_path,
                        input_checksum = excluded.input_checksum,
                        status = excluded.status,
                        retry_count = excluded.retry_count,
                        max_retries = excluded.max_retries,
                        first_received_at = COALESCE(job_processing_state.first_received_at, excluded.first_received_at),
                        last_received_at = excluded.last_received_at,
                        processing_started_at = excluded.processing_started_at,
                        completed_at = NULL,
                        failed_at = NULL,
                        error_type = NULL,
                        error_message = NULL,
                        trace_id = excluded.trace_id,
                        updated_at = excluded.updated_at
                    """,
                    (
                        job_id,
                        stage,
                        source,
                        input_path,
                        output_path,
                        input_checksum,
                        STATUS_PROCESSING,
                        int(retry_count),
                        int(max_retries),
                        first_received,
                        now,
                        now,
                        trace_id,
                        now,
                        now,
                    ),
                )
                conn.commit()
        return True

    def mark_received(
        self,
        *,
        job_id: str,
        stage: str,
        source: str,
        input_path: str | None,
        output_path: str | None,
        input_checksum: str | None,
        retry_count: int,
        max_retries: int,
        trace_id: str | None,
        error_type: str | None = None,
        error_message: str | None = None,
    ) -> None:
        self._upsert_status(
            job_id=job_id,
            stage=stage,
            source=source,
            input_path=input_path,
            output_path=output_path,
            input_checksum=input_checksum,
            status=STATUS_RECEIVED,
            retry_count=retry_count,
            max_retries=max_retries,
            trace_id=trace_id,
            error_type=error_type,
            error_message=error_message,
            processing_started_at=None,
            completed_at=None,
            failed_at=None,
        )

    def mark_completed(
        self,
        *,
        job_id: str,
        stage: str,
        source: str,
        input_path: str | None,
        output_path: str | None,
        input_checksum: str | None,
        retry_count: int,
        max_retries: int,
        trace_id: str | None,
    ) -> None:
        now = _utc_now()
        self._upsert_status(
            job_id=job_id,
            stage=stage,
            source=source,
            input_path=input_path,
            output_path=output_path,
            input_checksum=input_checksum,
            status=STATUS_COMPLETED,
            retry_count=retry_count,
            max_retries=max_retries,
            trace_id=trace_id,
            processing_started_at=None,
            completed_at=now,
            failed_at=None,
        )

    def mark_failed(
        self,
        *,
        job_id: str,
        stage: str,
        source: str,
        input_path: str | None,
        output_path: str | None,
        input_checksum: str | None,
        retry_count: int,
        max_retries: int,
        trace_id: str | None,
        error_type: str,
        error_message: str,
    ) -> None:
        now = _utc_now()
        self._upsert_status(
            job_id=job_id,
            stage=stage,
            source=source,
            input_path=input_path,
            output_path=output_path,
            input_checksum=input_checksum,
            status=STATUS_FAILED,
            retry_count=retry_count,
            max_retries=max_retries,
            trace_id=trace_id,
            error_type=error_type,
            error_message=error_message,
            processing_started_at=None,
            completed_at=None,
            failed_at=now,
        )

    def get(self, *, job_id: str, stage: str) -> Optional[JobStateRecord]:
        self._ensure_setup()
        with closing(self._connect()) as conn:
            row = conn.execute(
                """
                SELECT job_id, stage, status, retry_count, max_retries, input_path, output_path,
                       error_type, error_message, trace_id
                FROM job_processing_state
                WHERE job_id = ? AND stage = ?
                """,
                (job_id, stage),
            ).fetchone()
        if row is None:
            return None
        return JobStateRecord(
            job_id=row["job_id"],
            stage=row["stage"],
            status=row["status"],
            retry_count=int(row["retry_count"]),
            max_retries=int(row["max_retries"]),
            input_path=row["input_path"],
            output_path=row["output_path"],
            error_type=row["error_type"],
            error_message=row["error_message"],
            trace_id=row["trace_id"],
        )

    def _upsert_status(
        self,
        *,
        job_id: str,
        stage: str,
        source: str,
        input_path: str | None,
        output_path: str | None,
        input_checksum: str | None,
        status: str,
        retry_count: int,
        max_retries: int,
        trace_id: str | None,
        error_type: str | None = None,
        error_message: str | None = None,
        processing_started_at: str | None = None,
        completed_at: str | None = None,
        failed_at: str | None = None,
    ) -> None:
        self._ensure_setup()
        now = _utc_now()
        with self._lock:
            with closing(self._connect()) as conn:
                conn.execute(
                    """
                    INSERT INTO job_processing_state (
                        job_id, stage, source, input_path, output_path, input_checksum, status,
                        retry_count, max_retries, first_received_at, last_received_at,
                        processing_started_at, completed_at, failed_at,
                        error_type, error_message, trace_id, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(job_id, stage) DO UPDATE SET
                        source = excluded.source,
                        input_path = excluded.input_path,
                        output_path = excluded.output_path,
                        input_checksum = excluded.input_checksum,
                        status = excluded.status,
                        retry_count = excluded.retry_count,
                        max_retries = excluded.max_retries,
                        first_received_at = COALESCE(job_processing_state.first_received_at, excluded.first_received_at),
                        last_received_at = excluded.last_received_at,
                        processing_started_at = excluded.processing_started_at,
                        completed_at = excluded.completed_at,
                        failed_at = excluded.failed_at,
                        error_type = excluded.error_type,
                        error_message = excluded.error_message,
                        trace_id = excluded.trace_id,
                        updated_at = excluded.updated_at
                    """,
                    (
                        job_id,
                        stage,
                        source,
                        input_path,
                        output_path,
                        input_checksum,
                        status,
                        int(retry_count),
                        int(max_retries),
                        now,
                        now,
                        processing_started_at,
                        completed_at,
                        failed_at,
                        error_type,
                        error_message,
                        trace_id,
                        now,
                        now,
                    ),
                )
                conn.commit()

