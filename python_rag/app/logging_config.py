"""Logging configuration for the RAG FastAPI app."""
from __future__ import annotations

import logging
from pathlib import Path
from app.settings import AppSettings


def env_flag(value: str | None) -> bool:
    return str(value or "").strip().lower() in ("1", "true", "yes")


def configure_app_logging(settings: AppSettings, *, logger_name: str) -> tuple[logging.Logger, bool, str]:
    log_level = settings.log_level
    logging.basicConfig(level=log_level, format="%(levelname)s:%(name)s:%(message)s")
    logger = logging.getLogger(logger_name)
    logger.setLevel(log_level)

    graph_debug = settings.graph_debug
    graph_debug_log = settings.graph_debug_log
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
