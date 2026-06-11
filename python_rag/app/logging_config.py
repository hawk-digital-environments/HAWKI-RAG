"""Logging configuration for the RAG FastAPI app."""
from __future__ import annotations

import logging
import re
from dataclasses import dataclass
from pathlib import Path
from app.settings import AppSettings


_SECRET_PATTERNS = (
    re.compile(r"(?i)(api[_-]?key)=[^\s&]+"),
    re.compile(r"(?i)(auth(?:orization)?)=[^\s&]+"),
    re.compile(r"(?i)(access[_-]?token)=[^\s&]+"),
    re.compile(r"(?i)(secret|password)=[^\s&]+"),
    re.compile(r"(?i)(Authorization:\s*)[^\r\n]+"),
)


def _scrub_secrets(message: str) -> str:
    sanitized = message
    for pattern in _SECRET_PATTERNS:
        sanitized = pattern.sub(lambda match: f"{match.group(1)}=<redacted>", sanitized)
    return sanitized


def _coerce_log_level(level_name: str) -> int:
    return getattr(logging, (level_name or "INFO").strip().upper(), logging.INFO)


def _has_file_handler(root_logger: logging.Logger, path: Path) -> bool:
    try:
        target = path.expanduser().resolve()
    except OSError:
        return False
    for handler in root_logger.handlers:
        if not isinstance(handler, logging.FileHandler):
            continue
        try:
            existing = Path(handler.baseFilename).resolve()
        except OSError:
            continue
        if existing == target:
            return True
    return False


def _add_file_handler(root_logger: logging.Logger, path: Path) -> None:
    log_path = path.expanduser()
    log_path.parent.mkdir(parents=True, exist_ok=True)
    if _has_file_handler(root_logger, log_path):
        return
    file_handler = logging.FileHandler(log_path, encoding="utf-8")
    file_handler.setLevel(logging.DEBUG)
    file_handler.setFormatter(_StructuredFormatter())
    root_logger.addHandler(file_handler)


@dataclass(frozen=True)
class _StructuredFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        # Keep format human-readable while consistently keying fields.
        message = _scrub_secrets(record.getMessage())
        return (
            f'level="{record.levelname}" '
            f'logger="{record.name}" '
            f'event="{message}"'
        )


def env_flag(value: str | None) -> bool:
    return str(value or "").strip().lower() in ("1", "true", "yes")


def configure_app_logging(settings: AppSettings, *, logger_name: str) -> tuple[logging.Logger, bool, str]:
    log_level = _coerce_log_level(settings.log_level)
    formatter = _StructuredFormatter()

    root_logger = logging.getLogger()
    root_logger.setLevel(log_level)

    if not any(
        isinstance(handler, logging.StreamHandler) and not isinstance(handler, logging.FileHandler)
        for handler in root_logger.handlers
    ):
        stream_handler = logging.StreamHandler()
        stream_handler.setLevel(log_level)
        stream_handler.setFormatter(formatter)
        root_logger.addHandler(stream_handler)

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
        _add_file_handler(root_logger, Path(graph_debug_log))
        logger.info("graph:debug_logging_to=%s", Path(graph_debug_log))

    return logger, graph_debug, graph_debug_log
