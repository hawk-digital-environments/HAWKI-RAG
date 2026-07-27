"""Logging helpers for Temporal RAG workers."""

from __future__ import annotations

import logging
import os
from pathlib import Path


def configure_logging(stage: str | None = None) -> None:
    handlers: list[logging.Handler] = [logging.StreamHandler()]
    log_path = _worker_log_path(stage)
    if log_path is not None:
        try:
            Path(log_path).expanduser().parent.mkdir(parents=True, exist_ok=True)
            handlers.append(logging.FileHandler(log_path, encoding="utf-8"))
        except OSError as exc:
            logging.basicConfig(
                level=logging.INFO,
                format="%(asctime)s %(levelname)s %(name)s %(message)s",
                handlers=handlers,
                force=True,
            )
            logging.getLogger(__name__).warning(
                "worker_log_file_unavailable path=%s error=%s",
                log_path,
                exc,
            )
            return

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
        handlers=handlers,
        force=True,
    )


def _worker_log_path(stage: str | None) -> str | None:
    stage_key = (stage or "").strip().replace("-", "_").upper()
    candidates: list[str | None] = []
    if stage_key:
        candidates.append(os.getenv(f"HAWKI_RAG_TEMPORAL_{stage_key}_LOG_PATH"))

    candidates.append(os.getenv("HAWKI_RAG_TEMPORAL_WORKER_LOG_PATH"))

    if stage_key:
        candidates.append(f"/shared/logs/{stage_key.lower()}_worker.log")

    for candidate in candidates:
        if candidate is not None and candidate.strip():
            return candidate.strip()

    return None


def log_event(logger: logging.Logger, event: str, **fields: object) -> None:
    safe_fields = {key: value for key, value in fields.items() if value is not None}
    logger.info("%s %s", event, safe_fields)


__all__ = ["configure_logging", "log_event"]
