"""Logging configuration for the RAG FastAPI app."""
from __future__ import annotations

import logging
from pathlib import Path
from typing import Mapping


def env_flag(value: str | None) -> bool:
    return str(value or "").strip().lower() in ("1", "true", "yes")


def configure_app_logging(env: Mapping[str, str], *, logger_name: str) -> tuple[logging.Logger, bool, str]:
    log_level = env.get("LOG_LEVEL", "INFO").upper()
    logging.basicConfig(level=log_level, format="%(levelname)s:%(name)s:%(message)s")
    logger = logging.getLogger(logger_name)
    logger.setLevel(log_level)

    graph_debug = env_flag(env.get("GRAPH_DEBUG"))
    graph_debug_log = str(env.get("GRAPH_DEBUG_LOG", "")).strip()
    if graph_debug:
        logging.getLogger("pipeline.ingest_logic").setLevel(logging.DEBUG)
        logging.getLogger("core.rag_service").setLevel(logging.DEBUG)
    else:
        logging.getLogger("utils.text_preprocessor").setLevel(logging.INFO)
    if graph_debug_log:
        log_path = Path(graph_debug_log)
        log_path.parent.mkdir(parents=True, exist_ok=True)
        file_handler = logging.FileHandler(log_path, encoding="utf-8")
        file_handler.setLevel(logging.DEBUG)
        file_handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s:%(name)s:%(message)s"))
        logging.getLogger().addHandler(file_handler)
        logger.info("graph:debug logging to %s", log_path)
    return logger, graph_debug, graph_debug_log
