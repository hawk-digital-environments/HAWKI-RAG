"""Application settings boundary for the FastAPI layer."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from typing import Mapping


def _env_str(env: Mapping[str, str], name: str, default: str) -> str:
    return (env.get(name, default) or default).strip()


def _env_bool(env: Mapping[str, str], name: str, default: bool = False) -> bool:
    value = str(env.get(name, "")).strip().lower()
    if not value:
        return default
    return value in {"1", "true", "yes", "on"}


def _env_int(env: Mapping[str, str], name: str, default: int) -> int:
    raw = env.get(name)
    if raw is None:
        return default
    value = str(raw).strip()
    if not value:
        return default
    try:
        return int(value)
    except ValueError:
        return default


def _env_float(env: Mapping[str, str], name: str, default: float) -> float:
    raw = env.get(name)
    if raw is None:
        return default
    value = str(raw).strip()
    if not value:
        return default
    try:
        return float(value)
    except ValueError:
        return default


def _env_path(env: Mapping[str, str], name: str, default: str) -> Path:
    return Path(_env_str(env, name, default)).expanduser()


@dataclass(frozen=True)
class AppSettings:
    """Typed app settings for request defaults and runtime bootstrap."""

    rag_default_provider: str
    qdrant_distance: str
    graph_engine: str
    reranker_mode: str
    reranker_mix_mode: bool
    reranker_mix_weight: float
    reranker_jina_model: str
    reranker_api_url: str
    chunk_size: int
    chunk_overlap_size: int
    ingest_batch_size: int
    log_level: str
    graph_debug: bool
    graph_debug_log: str
    public_dir: Path
    cuda_visible_devices: str
    nvidia_visible_devices: str
    startup_checks_enabled: bool = True
    startup_check_attempts: int = 3
    startup_check_timeout_seconds: float = 3.0
    startup_check_backoff_seconds: float = 0.5


def load_app_settings(env: Mapping[str, str] | None = None) -> AppSettings:
    """Load application settings with stable fallbacks."""

    env_map = env or os.environ
    project_root = Path(__file__).resolve().parent.parent.parent
    return AppSettings(
        rag_default_provider=_env_str(env_map, "RAG_DEFAULT_PROVIDER", "ollama"),
        qdrant_distance=_env_str(env_map, "QDRANT_DISTANCE", "Cosine"),
        graph_engine=_env_str(env_map, "GRAPH_ENGINE", "raganything"),
        reranker_mode=_env_str(env_map, "RERANKER_MODE", "none"),
        reranker_mix_mode=_env_bool(env_map, "RERANKER_MIX_MODE", True),
        reranker_mix_weight=_env_float(env_map, "RERANKER_MIX_WEIGHT", 0.5),
        reranker_jina_model=_env_str(env_map, "JINA_RERANKER_MODEL", "jina-reranker-v2-base-multilingual"),
        reranker_api_url=_env_str(env_map, "RERANKER_API_URL", ""),
        chunk_size=_env_int(env_map, "CHUNK_SIZE", 1200),
        chunk_overlap_size=_env_int(env_map, "CHUNK_OVERLAP_SIZE", 250),
        ingest_batch_size=_env_int(env_map, "INGEST_BATCH_SIZE", 64),
        log_level=_env_str(env_map, "LOG_LEVEL", "INFO").upper(),
        graph_debug=_env_bool(env_map, "GRAPH_DEBUG", False),
        graph_debug_log=_env_str(env_map, "GRAPH_DEBUG_LOG", ""),
        public_dir=_env_path(
            env_map,
            "HAWKI_RAG_PUBLIC_DIR",
            str(project_root / "public"),
        ),
        cuda_visible_devices=_env_str(env_map, "CUDA_VISIBLE_DEVICES", "unset"),
        nvidia_visible_devices=_env_str(env_map, "NVIDIA_VISIBLE_DEVICES", "unset"),
        startup_checks_enabled=_env_bool(env_map, "STARTUP_CHECKS_ENABLED", True),
        startup_check_attempts=max(1, _env_int(env_map, "STARTUP_CHECK_ATTEMPTS", 3)),
        startup_check_timeout_seconds=max(0.5, _env_float(env_map, "STARTUP_CHECK_TIMEOUT_SECONDS", 3.0)),
        startup_check_backoff_seconds=max(0.0, _env_float(env_map, "STARTUP_CHECK_BACKOFF_SECONDS", 0.5)),
    )
