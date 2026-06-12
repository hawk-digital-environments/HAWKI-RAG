"""Configuration, payload, and error helpers for the Ollama provider."""

from __future__ import annotations

from dataclasses import dataclass
import os
import re
from typing import Any


@dataclass(frozen=True)
class OllamaChatOptions:
    timeout: float
    retries: int
    backoff: float
    jitter: float
    temperature: float
    num_predict: int
    top_p: float


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


def embedding_timeout_from_env() -> float:
    return _float_env("OLLAMA_EMBED_TIMEOUT", 60.0)


def embedding_max_chars_from_env() -> int:
    return _int_env("OLLAMA_EMBED_MAX_CHARS", 4000)


def clean_embedding_text(text: str, *, max_chars: int | None = None) -> str:
    """Remove noisy control characters and bound embedding prompt size."""

    cleaned = "".join(
        char for char in str(text or "") if char in ("\n", "\r", "\t") or ord(char) >= 32
    )
    cleaned = cleaned.encode("utf-8", errors="ignore").decode("utf-8", errors="ignore")
    cleaned = re.sub(r"[ \t]+", " ", cleaned)
    cleaned = re.sub(r"\n{3,}", "\n\n", cleaned).strip()
    max_chars = embedding_max_chars_from_env() if max_chars is None else max_chars
    if max_chars > 0:
        cleaned = cleaned[:max_chars]
    return cleaned or " "


def is_ollama_nan_embedding_error(status: int | None, message: str) -> bool:
    msg = (message or "").lower()
    if "unsupported value: nan" not in msg:
        return False
    return status in (None, 500)


def embed_nan_zero_fallback_enabled() -> bool:
    return os.environ.get("OLLAMA_EMBED_NAN_ZERO_FALLBACK", "true").strip().lower() in (
        "1",
        "true",
        "yes",
        "on",
    )


def chat_options_from_env(temperature: float | None = None) -> OllamaChatOptions:
    if temperature is None:
        env_temp = os.environ.get("OLLAMA_TEMPERATURE", "").strip()
        if env_temp:
            try:
                temperature = float(env_temp)
            except ValueError:
                temperature = None
    return OllamaChatOptions(
        timeout=_float_env("OLLAMA_CHAT_TIMEOUT", 120.0),
        retries=max(0, _int_env("OLLAMA_CHAT_RETRIES", 0)),
        backoff=max(0.0, _float_env("OLLAMA_CHAT_BACKOFF", 1.5)),
        jitter=max(0.0, _float_env("OLLAMA_CHAT_JITTER", 0.2)),
        temperature=0.3 if temperature is None else temperature,
        num_predict=_int_env("OLLAMA_NUM_PREDICT", 900),
        top_p=_float_env("OLLAMA_TOP_P", 0.9),
    )


def build_chat_payload(
    *,
    model: str,
    system: str,
    messages: list[Any],
    options: OllamaChatOptions,
) -> dict[str, Any]:
    return {
        "model": model,
        "messages": [{"role": "system", "content": system}] + messages,
        "stream": False,
        "options": {
            "temperature": options.temperature,
            "top_p": options.top_p,
            "num_predict": options.num_predict,
        },
    }


def generate_endpoint_candidates(base_url: str) -> list[str]:
    candidates = [f"{base_url}/generate"]
    if base_url.endswith("/api"):
        base_no_api = base_url[:-4]
        candidates.append(f"{base_no_api}/generate")
        candidates.append(f"{base_no_api}/api/generate")
    return candidates


def extract_error_message(resp: Any) -> str:
    detail = ""
    if resp is not None:
        try:
            payload = resp.json()
        except ValueError:
            payload = None
        if isinstance(payload, dict):
            detail = payload.get("error") or payload.get("message") or str(payload)
        else:
            detail = resp.text
    return detail or ""


def _int_env(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, default))
    except ValueError:
        return default


def _float_env(name: str, default: float) -> float:
    try:
        return float(os.environ.get(name, default))
    except ValueError:
        return default


__all__ = [
    "OllamaChatOptions",
    "build_chat_payload",
    "chat_options_from_env",
    "clean_embedding_text",
    "embed_nan_zero_fallback_enabled",
    "embedding_timeout_from_env",
    "extract_error_message",
    "generate_endpoint_candidates",
    "infer_embedding_dim",
    "is_ollama_nan_embedding_error",
]
