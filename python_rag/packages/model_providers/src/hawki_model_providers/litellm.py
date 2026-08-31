"""LiteLLM adapter for its OpenAI-compatible HTTP API."""

from __future__ import annotations

import math
import os
import re
from typing import Any, Protocol, cast
from urllib.parse import urlsplit

import requests


_DEFAULT_BASE_URL = "http://litellm:4000/v1"
_DEFAULT_CHAT_MODEL = "hawki-chat"
_DEFAULT_EMBED_MODEL = "hawki-embedding"
_DEFAULT_VISION_MODEL = "hawki-vision"
_ALLOWED_MESSAGE_ROLES = {"assistant", "developer", "system", "tool", "user"}


class LiteLLMConfigurationError(ValueError):
    """Raised when the LiteLLM provider configuration is unusable."""


class _LiteLLMHTTPResponse(Protocol):
    """Response surface consumed from the injected HTTP client."""

    status_code: int

    def json(self) -> object: ...


class _LiteLLMHTTPClient(Protocol):
    """HTTP operation required by the LiteLLM adapter."""

    def post(
        self,
        url: str,
        *,
        json: dict[str, Any],
        headers: dict[str, str],
        timeout: float,
    ) -> _LiteLLMHTTPResponse: ...


def _required_setting(name: str, default: str) -> str:
    value = str(os.environ.get(name, default) or "").strip()
    if not value:
        raise LiteLLMConfigurationError(f"{name} must be a non-empty value.")
    return value


def _positive_float_setting(name: str, default: float) -> float:
    raw_value = str(os.environ.get(name, default) or "").strip()
    try:
        value = float(raw_value)
    except ValueError as exc:
        raise LiteLLMConfigurationError(f"{name} must be a positive number.") from exc
    if not math.isfinite(value) or value <= 0:
        raise LiteLLMConfigurationError(f"{name} must be a positive number.")
    return value


def _optional_shared_timeout() -> float | None:
    raw_value = str(os.environ.get("LITELLM_TIMEOUT", "") or "").strip()
    if not raw_value:
        return None
    return _positive_float_setting("LITELLM_TIMEOUT", 120.0)


def _normalize_base_url(raw_url: str) -> str:
    base_url = raw_url.strip().rstrip("/")
    parsed = urlsplit(base_url)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise LiteLLMConfigurationError(
            "LITELLM_API_URL must be an absolute http:// or https:// URL."
        )
    if parsed.username or parsed.password:
        raise LiteLLMConfigurationError(
            "LITELLM_API_URL must not contain credentials; use LITELLM_API_KEY instead."
        )
    if parsed.query or parsed.fragment:
        raise LiteLLMConfigurationError(
            "LITELLM_API_URL must not contain a query string or fragment."
        )
    return base_url


def _temperature(value: float | None, default: float) -> float:
    resolved = default if value is None else value
    try:
        normalized = float(resolved)
    except (TypeError, ValueError) as exc:
        raise ValueError(
            "LiteLLM temperature must be a number between 0 and 2."
        ) from exc
    if not math.isfinite(normalized) or not 0 <= normalized <= 2:
        raise ValueError("LiteLLM temperature must be a number between 0 and 2.")
    return normalized


def _normalize_image_url(value: object) -> str | None:
    raw_value = str(value or "").strip()
    if not raw_value:
        return None
    if raw_value.lower().startswith(("data:", "http://", "https://")):
        return raw_value
    return f"data:image/png;base64,{raw_value}"


def _normalize_content_part(part: object) -> dict[str, Any]:
    if not isinstance(part, dict):
        raise ValueError("LiteLLM message content parts must be objects.")

    part_type = str(part.get("type") or "").strip().lower()
    if part_type in {"text", "input_text"}:
        return {"type": "text", "text": str(part.get("text") or "")}
    if part_type in {"image_url", "input_image"}:
        image_value = (
            part.get("image_url") or part.get("image") or part.get("image_data")
        )
        detail: str | None = None
        if isinstance(image_value, dict):
            detail = str(image_value.get("detail") or "").strip() or None
            image_value = image_value.get("url")
        image_url = _normalize_image_url(image_value)
        if image_url is None:
            raise ValueError(
                "LiteLLM image content must include a non-empty URL or base64 value."
            )
        normalized_image: dict[str, str] = {"url": image_url}
        if detail:
            normalized_image["detail"] = detail
        return {"type": "image_url", "image_url": normalized_image}
    raise ValueError(
        f"Unsupported LiteLLM message content type: {part_type or '<missing>'}."
    )


def _normalize_message(message: object, *, index: int) -> dict[str, Any]:
    if not isinstance(message, dict):
        raise ValueError(f"LiteLLM message at index {index} must be an object.")

    role = str(message.get("role") or "").strip().lower()
    if role not in _ALLOWED_MESSAGE_ROLES:
        raise ValueError(f"LiteLLM message at index {index} has an unsupported role.")

    raw_content = message.get("content", "")
    if isinstance(raw_content, str):
        content: str | list[dict[str, Any]] = raw_content
    elif isinstance(raw_content, list):
        content = [_normalize_content_part(part) for part in raw_content]
    else:
        raise ValueError(f"LiteLLM message at index {index} has invalid content.")

    raw_images = message.get("images")
    if raw_images is not None:
        images = raw_images if isinstance(raw_images, list) else [raw_images]
        multimodal_content: list[dict[str, Any]]
        if isinstance(content, str):
            multimodal_content = [{"type": "text", "text": content}] if content else []
        else:
            multimodal_content = content
        for image in images:
            image_url = _normalize_image_url(image)
            if image_url:
                multimodal_content.append(
                    {"type": "image_url", "image_url": {"url": image_url}}
                )
        content = multimodal_content

    normalized: dict[str, Any] = {"role": role, "content": content}
    for optional_key in ("name", "tool_call_id"):
        optional_value = message.get(optional_key)
        if isinstance(optional_value, str) and optional_value.strip():
            normalized[optional_key] = optional_value.strip()
    return normalized


def _normalize_messages(messages: list[Any] | None) -> list[dict[str, Any]]:
    if messages is None:
        return []
    if not isinstance(messages, list):
        raise ValueError("LiteLLM messages must be a list.")
    return [
        _normalize_message(message, index=index)
        for index, message in enumerate(messages)
    ]


def _chat_content(payload: object) -> str | None:
    if not isinstance(payload, dict):
        return None
    choices = payload.get("choices")
    if not isinstance(choices, list) or not choices or not isinstance(choices[0], dict):
        return None
    message = choices[0].get("message")
    if not isinstance(message, dict):
        return None
    content = message.get("content")
    if isinstance(content, str):
        return content if content.strip() else None
    if not isinstance(content, list):
        return None

    text_parts: list[str] = []
    for part in content:
        if not isinstance(part, dict):
            continue
        text_value = part.get("text") or part.get("content")
        if isinstance(text_value, str) and text_value:
            text_parts.append(text_value)
    combined = "".join(text_parts)
    return combined if combined.strip() else None


class LiteLLMProvider:
    """Provide HAWKI's model interface through a LiteLLM proxy."""

    def __init__(self, *, http_client: _LiteLLMHTTPClient | None = None) -> None:
        self._http_client = (
            cast(_LiteLLMHTTPClient, requests) if http_client is None else http_client
        )
        self.base = _normalize_base_url(
            _required_setting("LITELLM_API_URL", _DEFAULT_BASE_URL)
        )
        self.key = str(os.environ.get("LITELLM_API_KEY", "") or "").strip()
        # Model selection is request-scoped: apply_provider_overrides injects
        # the dataset-pinned models from Laravel before any call is made.
        self.rag_model: str | None = None
        self.embed_model: str | None = None
        self.vision_model: str | None = None

        shared_timeout = _optional_shared_timeout()
        self.chat_timeout = _positive_float_setting(
            "LITELLM_CHAT_TIMEOUT",
            shared_timeout if shared_timeout is not None else 240.0,
        )
        self.embed_timeout = _positive_float_setting(
            "LITELLM_EMBED_TIMEOUT",
            shared_timeout if shared_timeout is not None else 60.0,
        )
        self.default_temperature = self._configured_temperature()
        self._last_embed_dim: int | None = None
        self._explicit_graph_model: str | None = None
        self._explicit_vision_model: str | None = None

    def _require_model(self, attribute: str, capability: str) -> str:
        model = getattr(self, attribute)
        if not model:
            raise RuntimeError(
                f"LiteLLMProvider.{attribute} is not set; the request must provide "
                f"the {capability} model explicitly (no environment fallback)."
            )
        return model

    @staticmethod
    def _configured_temperature() -> float:
        raw_value = str(os.environ.get("LITELLM_TEMPERATURE", "") or "").strip()
        if not raw_value:
            return 0.3
        try:
            value = float(raw_value)
        except ValueError as exc:
            raise LiteLLMConfigurationError(
                "LITELLM_TEMPERATURE must be a number between 0 and 2."
            ) from exc
        try:
            return _temperature(value, value)
        except ValueError as exc:
            raise LiteLLMConfigurationError(
                str(exc).replace("LiteLLM temperature", "LITELLM_TEMPERATURE")
            ) from exc

    def embed(self, text: str) -> list[float]:
        embed_model = self._require_model("embed_model", "embedding")
        payload = {
            "model": embed_model,
            "input": str(text or ""),
        }
        response = self._post_json(
            endpoint="embeddings",
            payload=payload,
            timeout=self.embed_timeout,
            operation="embeddings",
        )
        data = response.get("data") if isinstance(response, dict) else None
        first_item = data[0] if isinstance(data, list) and data else None
        embedding = (
            first_item.get("embedding") if isinstance(first_item, dict) else None
        )
        if not isinstance(embedding, list) or not embedding:
            raise RuntimeError("LiteLLM embeddings returned an unexpected response.")

        vector: list[float] = []
        for value in embedding:
            if isinstance(value, bool):
                raise RuntimeError(
                    "LiteLLM embeddings returned a non-numeric vector value."
                )
            try:
                numeric_value = float(value)
            except (TypeError, ValueError) as exc:
                raise RuntimeError(
                    "LiteLLM embeddings returned a non-numeric vector value."
                ) from exc
            if not math.isfinite(numeric_value):
                raise RuntimeError(
                    "LiteLLM embeddings returned a non-finite vector value."
                )
            vector.append(numeric_value)

        self._last_embed_dim = len(vector)
        return vector

    def chat(
        self,
        system: str,
        messages: list[Any],
        *,
        temperature: float | None = None,
    ) -> str:
        rag_model = self._require_model("rag_model", "chat")
        normalized_messages = _normalize_messages(messages)
        payload = {
            "model": rag_model,
            "messages": [{"role": "system", "content": str(system or "")}]
            + normalized_messages,
            "temperature": _temperature(temperature, self.default_temperature),
            "stream": False,
        }
        response = self._post_json(
            endpoint="chat/completions",
            payload=payload,
            timeout=self.chat_timeout,
            operation="chat",
        )
        content = _chat_content(response)
        if content is None:
            raise RuntimeError("LiteLLM chat returned an unexpected or empty response.")
        return content

    def vision_chat(
        self,
        system: str,
        prompt: str,
        *,
        image_data: str | None = None,
        messages: list[Any] | None = None,
        temperature: float | None = None,
    ) -> str:
        normalized_messages = _normalize_messages(messages)
        had_history = bool(normalized_messages)
        if not any(message["role"] == "system" for message in normalized_messages):
            normalized_messages.insert(
                0, {"role": "system", "content": str(system or "")}
            )

        image_url = _normalize_image_url(image_data)
        if prompt or image_url or not had_history:
            if image_url:
                user_content: str | list[dict[str, Any]] = []
                if prompt:
                    user_content.append({"type": "text", "text": str(prompt)})
                user_content.append(
                    {"type": "image_url", "image_url": {"url": image_url}}
                )
            else:
                user_content = str(prompt or "")
            normalized_messages.append({"role": "user", "content": user_content})

        vision_model = self._require_model("vision_model", "vision")
        payload = {
            "model": vision_model,
            "messages": normalized_messages,
            "temperature": _temperature(temperature, self.default_temperature),
            "stream": False,
        }
        response = self._post_json(
            endpoint="chat/completions",
            payload=payload,
            timeout=self.chat_timeout,
            operation="vision chat",
        )
        content = _chat_content(response)
        if content is None:
            raise RuntimeError(
                "LiteLLM vision chat returned an unexpected or empty response."
            )
        return content

    def _post_json(
        self,
        *,
        endpoint: str,
        payload: dict[str, Any],
        timeout: float,
        operation: str,
    ) -> dict[str, Any]:
        headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
        }
        if self.key:
            headers["Authorization"] = f"Bearer {self.key}"

        try:
            response = self._http_client.post(
                f"{self.base}/{endpoint.lstrip('/')}",
                json=payload,
                headers=headers,
                timeout=timeout,
            )
        except requests.Timeout as exc:
            detail = self._safe_detail(exc)
            raise RuntimeError(
                f"LiteLLM {operation} request timed out: {detail}"
            ) from exc
        except requests.RequestException as exc:
            detail = self._safe_detail(exc)
            raise RuntimeError(f"LiteLLM {operation} request failed: {detail}") from exc

        try:
            response_payload = response.json()
        except (TypeError, ValueError):
            response_payload = None

        status_code = int(getattr(response, "status_code", 0) or 0)
        if not 200 <= status_code < 300:
            detail = self._http_error_detail(response_payload)
            raise RuntimeError(
                f"LiteLLM {operation} HTTP error ({status_code}): {detail}"
            )
        if not isinstance(response_payload, dict):
            raise RuntimeError(f"LiteLLM {operation} returned a non-JSON response.")
        return response_payload

    def _http_error_detail(self, payload: object) -> str:
        detail: object | None = None
        if isinstance(payload, dict):
            error = payload.get("error")
            if isinstance(error, dict):
                detail = error.get("message") or error.get("type") or error.get("code")
            elif isinstance(error, str):
                detail = error
            if detail is None and isinstance(payload.get("message"), str):
                detail = payload["message"]
        return self._safe_detail(detail or "Upstream request failed.")

    def _safe_detail(self, value: object) -> str:
        detail = str(value or "Upstream request failed.")
        if self.key:
            detail = detail.replace(self.key, "<redacted>")
        detail = re.sub(r"(?i)bearer\s+[^\s,;]+", "Bearer <redacted>", detail)
        return detail[:500]


__all__ = ["LiteLLMConfigurationError", "LiteLLMProvider"]
