"""Transport-agnostic model-request hygiene shared by provider clients."""

from __future__ import annotations

from dataclasses import dataclass
import os
import re
from typing import Any


@dataclass(frozen=True)
class ChatOptions:
    """Retry and sampling knobs resolved from the environment."""

    timeout: float
    retries: int
    backoff: float
    jitter: float
    temperature: float
    num_predict: int
    top_p: float


def _float_env(name: str, default: float) -> float:
    try:
        return float(os.environ.get(name, default))
    except (TypeError, ValueError):
        return default


def _int_env(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, default))
    except (TypeError, ValueError):
        return default


def embedding_timeout_from_env() -> float:
    return _float_env("OLLAMA_EMBED_TIMEOUT", 60.0)


def embedding_max_chars_from_env() -> int:
    return _int_env("OLLAMA_EMBED_MAX_CHARS", 4000)


def embed_nan_zero_fallback_enabled() -> bool:
    value = os.environ.get("OLLAMA_EMBED_NAN_ZERO_FALLBACK", "").strip().lower()
    if not value:
        return True
    return value in {"1", "true", "yes", "on"}


def infer_embedding_dim(model_name: str, last_dim: int | None = None) -> int:
    """Infer embedding dimension from model name with the previous response as priority."""

    if last_dim and last_dim > 0:
        return last_dim
    name = str(model_name or "").lower()
    if "bge-m3" in name:
        return 1024
    if "text-embedding-3-large" in name:
        return 3072
    if "text-embedding-3-small" in name:
        return 1536
    return 1024


def clean_embedding_text(text: str, *, max_chars: int | None = None) -> str:
    """Remove noisy control characters and bound embedding prompt size."""

    cleaned = "".join(
        char
        for char in str(text or "")
        if char in ("\n", "\r", "\t") or ord(char) >= 32
    )
    cleaned = cleaned.encode("utf-8", errors="ignore").decode("utf-8", errors="ignore")
    cleaned = re.sub(r"[ \t]+", " ", cleaned)
    cleaned = re.sub(r"\n{3,}", "\n\n", cleaned).strip()
    max_chars = embedding_max_chars_from_env() if max_chars is None else max_chars
    if max_chars > 0:
        cleaned = cleaned[:max_chars]
    return cleaned or " "


def is_nan_embedding_failure(payload: Any) -> bool:
    """Detect Ollama's known NaN serialization failure across error shapes."""

    message = str(payload).lower()
    return "nan" in message and (
        "serializ" in message
        or "embedding" in message
        or "json" in message
        or "invalid" in message
    )


def chat_options_from_env(temperature: float | None = None) -> ChatOptions:
    if temperature is None:
        env_temp = os.environ.get("OLLAMA_TEMPERATURE", "").strip()
        if env_temp:
            try:
                temperature = float(env_temp)
            except ValueError:
                temperature = None
    return ChatOptions(
        timeout=_float_env("OLLAMA_CHAT_TIMEOUT", 120.0),
        retries=max(0, _int_env("OLLAMA_CHAT_RETRIES", 0)),
        backoff=max(0.0, _float_env("OLLAMA_CHAT_BACKOFF", 1.5)),
        jitter=max(0.0, _float_env("OLLAMA_CHAT_JITTER", 0.2)),
        temperature=0.3 if temperature is None else temperature,
        num_predict=_int_env("OLLAMA_NUM_PREDICT", 900),
        top_p=_float_env("OLLAMA_TOP_P", 0.9),
    )


def sanitize_vector(values: list[float]) -> list[float]:
    """Replace non-finite components so downstream stores stay healthy."""

    out: list[float] = []
    for component in values:
        try:
            value = float(component)
        except (TypeError, ValueError):
            value = 0.0
        out.append(value if (value == value and abs(value) != float("inf")) else 0.0)
    return out


__all__ = [
    "ChatOptions",
    "chat_options_from_env",
    "clean_embedding_text",
    "embed_nan_zero_fallback_enabled",
    "embedding_max_chars_from_env",
    "embedding_timeout_from_env",
    "infer_embedding_dim",
    "is_nan_embedding_failure",
    "sanitize_vector",
]
