from __future__ import annotations

import json
import logging
from typing import Any


def pipeline_log(
    logger: logging.Logger,
    level: int,
    *,
    stage: str,
    status: str,
    job_id: Any = None,
    doc_id: Any = None,
    error_message: Any = None,
    **fields: Any,
) -> None:
    payload = {
        "event": "pipeline.stage",
        "stage": stage,
        "status": status,
        "job_id": _nullable_string(job_id),
        "doc_id": _nullable_string(doc_id),
        "error_message": _nullable_string(error_message),
    }
    payload.update(fields)
    logger.log(level, json.dumps(payload, ensure_ascii=False, sort_keys=True, default=str))


def _nullable_string(value: Any) -> str | None:
    if value is None or value == "":
        return None
    return str(value)
