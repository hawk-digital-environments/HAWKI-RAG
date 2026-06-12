"""Logging helpers for Temporal RAG workers."""

from __future__ import annotations

import logging
from typing import Any


def configure_logging() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )


def log_event(logger: logging.Logger, event: str, **fields: Any) -> None:
    safe_fields = {key: value for key, value in fields.items() if value is not None}
    logger.info("%s %s", event, safe_fields)


__all__ = ["configure_logging", "log_event"]
