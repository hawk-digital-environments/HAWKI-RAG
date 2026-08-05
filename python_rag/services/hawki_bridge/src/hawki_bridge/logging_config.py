"""Bridge logging without ingest/RAG-Anything side effects."""

from __future__ import annotations

import logging

from hawki_bridge.settings import BridgeSettings


def configure_logging(settings: BridgeSettings, *, logger_name: str) -> logging.Logger:
    level = getattr(logging, settings.log_level, logging.INFO)
    logging.basicConfig(
        level=level,
        format='level="%(levelname)s" logger="%(name)s" event="%(message)s"',
    )
    logger = logging.getLogger(logger_name)
    logger.setLevel(level)
    return logger


__all__ = ["configure_logging"]
