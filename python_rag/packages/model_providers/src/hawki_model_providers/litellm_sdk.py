"""Model provider backed by the LiteLLM Python SDK.

One client speaks to every backend: direct Ollama (``ollama/`` and
``ollama_chat/`` model prefixes), a LiteLLM proxy (``openai/`` prefix with
the proxy as ``api_base``), and hosted providers (``openai/``,
``anthropic/``, ``gemini/``, ...). Model strings are provider-prefixed —
the prefix routes the request; unprefixed names get the provider's default
prefix so dataset-pinned legacy values keep working.

The transport-agnostic hygiene inherited from the former native clients
lives in :mod:`hawki_model_providers.helpers`: prompt cleaning, embedding
dimension inference, the NaN zero-vector fallback, and chat retry knobs.
"""

from __future__ import annotations

import logging
import os
import random
import time
from typing import Any

import litellm

from hawki_model_providers.helpers import (
    chat_options_from_env,
    clean_embedding_text,
    embed_nan_zero_fallback_enabled,
    embedding_timeout_from_env,
    infer_embedding_dim,
    is_nan_embedding_failure,
    sanitize_vector,
)

logger = logging.getLogger(__name__)

_OLLAMA_NATIVE_PREFIX = "ollama/"
_OLLAMA_CHAT_PREFIX = "ollama_chat/"


def _env(name: str, default: str = "") -> str:
    return os.environ.get(name, "").strip()


def _ollama_api_base() -> str:
    """Ollama base URL without the native ``/api`` suffix."""

    configured = _env("OLLAMA_API_URL", "http://ollama:11434/api")
    if configured.endswith("/api"):
        configured = configured[: -len("/api")]
    return configured.rstrip("/")


def _api_base_for(provider_name: str) -> str | None:
    if provider_name == "litellm":
        return _env("LITELLM_API_URL") or None
    return _ollama_api_base()


def _api_key_for(provider_name: str) -> str | None:
    if provider_name == "litellm":
        return _env("LITELLM_API_KEY") or None
    # Ollama ignores the key but the OpenAI client surface requires one.
    return "ollama"


class LiteLLMSDKProvider:
    """Provide HAWKI's model interface through the LiteLLM SDK."""

    def __init__(self, provider_name: str = "ollama") -> None:
        self.provider_name = provider_name
        self.base = _api_base_for(provider_name)
        self.api_key = _api_key_for(provider_name)
        # Model selection is request-scoped: apply_provider_overrides injects
        # the dataset-pinned models from Laravel before any call is made.
        self.embed_model: str | None = None
        self.rag_model: str | None = None
        self.vision_model: str | None = None
        self._last_embed_dim: int | None = None

    # -- Routing -----------------------------------------------------

    def _route(self, model: str, *, chat: bool) -> str:
        """Return the provider-prefixed model string the SDK routes on."""

        if "/" in model:
            return model
        if self.provider_name == "ollama":
            return (_OLLAMA_CHAT_PREFIX if chat else _OLLAMA_NATIVE_PREFIX) + model
        if self.provider_name == "litellm":
            # Proxy aliases (hawki-ollama-chat, ...) resolve server-side.
            return model
        return model

    def _keep_alive(self) -> dict[str, Any]:
        """Pass the configured keep-alive through on the Ollama route."""

        if self.provider_name != "ollama":
            return {}
        value = _env("OLLAMA_KEEP_ALIVE")
        return {"keep_alive": value} if value else {}

    def _request_kwargs(self, *, chat: bool) -> dict[str, Any]:
        timeout = (
            chat_options_from_env().timeout
            if chat
            else embedding_timeout_from_env()
        )
        kwargs: dict[str, Any] = {
            "api_base": self.base,
            "api_key": self.api_key,
            "timeout": timeout,
        }
        kwargs.update(self._keep_alive())
        return {key: value for key, value in kwargs.items() if value is not None}

    def _require_model(self, attribute: str, capability: str) -> str:
        model = getattr(self, attribute)
        if not model:
            raise RuntimeError(
                f"LiteLLMSDKProvider.{attribute} is not set; the request must "
                f"provide the {capability} model explicitly (no environment "
                "fallback)."
            )
        return model

    @staticmethod
    def _sleep_backoff(attempt: int, options: Any) -> None:
        if options.backoff <= 0:
            return
        delay = options.backoff * max(1, attempt)
        if options.jitter > 0:
            delay += random.uniform(0.0, options.jitter) * delay
        time.sleep(delay)

    # -- Capabilities ------------------------------------------------

    def embed(self, text: str) -> list[float]:
        model = self._require_model("embed_model", "embedding")
        prompt = clean_embedding_text(text)
        try:
            response = litellm.embedding(
                model=self._route(model, chat=False),
                input=[prompt],
                **self._request_kwargs(chat=False),
            )
        except Exception as exc:
            if embed_nan_zero_fallback_enabled() and is_nan_embedding_failure(exc):
                dim = infer_embedding_dim(model, self._last_embed_dim)
                logger.warning(
                    "LiteLLM SDK embedding hit Ollama's NaN serialization bug; "
                    "using zero-vector fallback (dim=%s, model=%s)",
                    dim,
                    model,
                )
                return [0.0] * dim
            raise RuntimeError(f"LiteLLM SDK embedding failed: {exc}") from exc

        data = getattr(response, "data", None) or []
        if not data:
            raise RuntimeError("LiteLLM SDK embedding returned no data")
        raw = data[0].get("embedding") if isinstance(data[0], dict) else getattr(
            data[0], "embedding", None
        )
        if not isinstance(raw, list) or not raw:
            raise RuntimeError("LiteLLM SDK embedding returned no vector")
        values = sanitize_vector([float(component) for component in raw])
        self._last_embed_dim = len(values) if values else self._last_embed_dim
        return values

    def chat(self, system: str, messages: list, *, temperature: float | None = None):
        model = self._require_model("rag_model", "chat")
        options = chat_options_from_env(temperature)
        payload_messages = [{"role": "system", "content": system}] + list(messages)
        max_attempts = max(1, options.retries + 1)
        last_error: Exception | None = None
        for attempt in range(1, max_attempts + 1):
            try:
                response = litellm.completion(
                    model=self._route(model, chat=True),
                    messages=payload_messages,
                    stream=False,
                    temperature=options.temperature,
                    top_p=options.top_p,
                    max_tokens=options.num_predict,
                    **self._request_kwargs(chat=True),
                )
            except Exception as exc:
                last_error = exc
                if attempt < max_attempts:
                    logger.warning(
                        "LiteLLM SDK chat failed (attempt %s/%s): %s",
                        attempt,
                        max_attempts,
                        exc,
                    )
                    self._sleep_backoff(attempt, options)
                    continue
                break
            return self._content_from(response)
        raise RuntimeError(f"LiteLLM SDK chat failed: {last_error}")

    def vision_chat(
        self,
        system: str,
        prompt: str,
        *,
        image_data: str | None = None,
        messages: list | None = None,
        temperature: float | None = None,
    ) -> str:
        model = self._require_model("vision_model", "vision")
        options = chat_options_from_env(temperature)
        payload_messages = self._vision_messages(system, prompt, image_data, messages)
        try:
            response = litellm.completion(
                model=self._route(model, chat=True),
                messages=payload_messages,
                stream=False,
                temperature=options.temperature,
                top_p=options.top_p,
                max_tokens=options.num_predict,
                **self._request_kwargs(chat=True),
            )
        except Exception as exc:
            raise RuntimeError(f"LiteLLM SDK vision chat failed: {exc}") from exc
        return self._content_from(response)

    # -- Helpers -----------------------------------------------------

    @staticmethod
    def _content_from(response: Any) -> str:
        choices = getattr(response, "choices", None) or []
        if not choices:
            raise RuntimeError("LiteLLM SDK returned no choices")
        message = getattr(choices[0], "message", None)
        content = getattr(message, "content", None)
        if isinstance(content, str) and content.strip():
            return content
        raise RuntimeError("LiteLLM SDK returned an empty reply")

    @staticmethod
    def _vision_messages(
        system: str,
        prompt: str,
        image_data: str | None,
        messages: list | None,
    ) -> list[dict[str, Any]]:
        user_content: list[dict[str, Any]] = [{"type": "text", "text": prompt}]
        image = LiteLLMSDKProvider._clean_image_data(image_data)
        if image:
            user_content.append(
                {
                    "type": "image_url",
                    "image_url": {"url": f"data:image/png;base64,{image}"},
                }
            )
        base = [{"role": "system", "content": system}]
        if messages:
            base.extend(
                {
                    "role": str(m.get("role", "user")),
                    "content": str(m.get("content", "")),
                }
                for m in messages
                if isinstance(m, dict)
            )
        base.append({"role": "user", "content": user_content})
        return base

    @staticmethod
    def _clean_image_data(value: object) -> str | None:
        raw = str(value or "").strip()
        if not raw:
            return None
        marker = ";base64,"
        index = raw.lower().find(marker)
        if index >= 0:
            return raw[index + len(marker) :].strip()
        return raw


__all__ = ["LiteLLMSDKProvider"]
