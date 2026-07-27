from __future__ import annotations

import json
import logging


def pipeline_log(
    logger: logging.Logger,
    level: int,
    *,
    stage: str,
    status: str,
    job_id: object = None,
    doc_id: object = None,
    error_message: object = None,
    **fields: object,
) -> None:
    payload: dict[str, object] = {
        "event": "application.workflows.stage",
        "stage": stage,
        "status": status,
        "job_id": _nullable_string(job_id),
        "doc_id": _nullable_string(doc_id),
        "error_message": _nullable_string(error_message),
    }
    payload.update(fields)
    logger.log(level, json.dumps(payload, ensure_ascii=False, sort_keys=True, default=str))


def _nullable_string(value: object) -> str | None:
    if value is None or value == "":
        return None
    return str(value)
