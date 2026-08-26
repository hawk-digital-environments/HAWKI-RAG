import logging
import math
import os
import random
import time
from typing import Any

import requests

from hawki_model_providers.ollama_helpers import (
    build_chat_payload,
    chat_options_from_env,
    clean_embedding_text,
    embed_nan_zero_fallback_enabled,
    embedding_timeout_from_env,
    extract_error_message,
    generate_endpoint_candidates,
    infer_embedding_dim,
    is_ollama_nan_embedding_error,
)


logger = logging.getLogger(__name__)


def _clean_ollama_image_data(value: object) -> str | None:
    raw = str(value or "").strip()
    if not raw:
        return None

    lower_raw = raw.lower()
    marker = ";base64,"
    marker_idx = lower_raw.find(marker)
    if marker_idx >= 0:
        return raw[marker_idx + len(marker) :].strip()
    if lower_raw.startswith("data:") and "," in raw:
        return raw.split(",", 1)[1].strip()
    return raw


def _requests_module() -> Any:
    return requests


def _request_exception_types(
    requests_module: Any | None = None,
) -> tuple[type[BaseException], type[BaseException], type[BaseException]]:
    module = requests_module or _requests_module()
    exceptions = module.exceptions
    return exceptions.HTTPError, exceptions.RequestException, exceptions.Timeout


class OllamaProvider:
    def __init__(self) -> None:
        base = os.environ.get("OLLAMA_API_URL", "http://ollama:11434/api").rstrip("/")
        self.base = base
        # Model selection is request-scoped: apply_provider_overrides injects
        # the dataset-pinned models from Laravel before any call is made.
        self.embed_model: str | None = None
        self.rag_model: str | None = None
        self.vision_model: str | None = None
        self._last_embed_dim: int | None = None
        self._explicit_graph_model: str | None = None
        self._explicit_vision_model: str | None = None

    def _require_model(self, attribute: str, capability: str) -> str:
        model = getattr(self, attribute)
        if not model:
            raise RuntimeError(
                f"OllamaProvider.{attribute} is not set; the request must provide "
                f"the {capability} model explicitly (no environment fallback)."
            )
        return model

    def _infer_embed_dim(self) -> int:
        return infer_embedding_dim(self.embed_model, self._last_embed_dim)

    @staticmethod
    def _clean_embedding_text(text: str) -> str:
        return clean_embedding_text(text)

    @staticmethod
    def _is_ollama_nan_embedding_error(status: int | None, message: str) -> bool:
        return is_ollama_nan_embedding_error(status, message)

    def embed(self, text: str) -> list[float]:
        embed_model = self._require_model("embed_model", "embedding")
        url = f"{self.base}/embeddings"
        timeout = embedding_timeout_from_env()
        requests_module = _requests_module()
        http_error, request_error, _timeout_error = _request_exception_types(
            requests_module
        )

        prompts = [str(text or "")]
        cleaned_prompt = self._clean_embedding_text(text)
        if cleaned_prompt != prompts[0]:
            prompts.append(cleaned_prompt)

        last_error: RuntimeError | None = None
        for attempt_idx, prompt in enumerate(prompts, start=1):
            try:
                r = requests_module.post(
                    url,
                    json={"model": embed_model, "prompt": prompt},
                    timeout=timeout,
                )
                r.raise_for_status()
            except http_error as exc:
                resp = exc.response
                status = resp.status_code if resp is not None else None
                detail = ""
                if resp is not None:
                    try:
                        payload = resp.json()
                    except ValueError:
                        payload = None
                    if isinstance(payload, dict):
                        detail = (
                            payload.get("error")
                            or payload.get("message")
                            or str(payload)
                        )
                    else:
                        detail = resp.text
                message = detail or str(exc)
                if (
                    status == 404
                    and "not" in message.lower()
                    and "model" in message.lower()
                ):
                    raise RuntimeError(
                        f"Ollama embeddings model '{embed_model}' is not installed. "
                        f"Run `ollama pull {embed_model}` inside the Ollama container or host."
                    ) from exc
                if self._is_ollama_nan_embedding_error(
                    status, message
                ) and attempt_idx < len(prompts):
                    logger.warning(
                        "Ollama embeddings returned NaN serialization error; retrying with sanitized prompt (attempt %s/%s)",
                        attempt_idx,
                        len(prompts),
                    )
                    continue
                last_error = RuntimeError(
                    f"Ollama embeddings HTTP error ({status}): {message}"
                )
            except request_error as exc:
                last_error = RuntimeError(f"Ollama embeddings request failed: {exc}")
            else:
                data = r.json()
                vec = data.get("embedding")
                if not isinstance(vec, list):
                    last_error = RuntimeError("Ollama embeddings: unexpected response")
                    continue
                out: list[float] = []
                for x in vec:
                    try:
                        fx = float(x)
                    except Exception:
                        fx = 0.0
                    if not math.isfinite(fx):
                        fx = 0.0
                    out.append(fx)
                self._last_embed_dim = len(out) if out else self._last_embed_dim
                return out

        # Optional resilience mode for Ollama's known NaN bug: return a zero vector
        # instead of aborting the whole document ingest.
        if last_error and self._is_ollama_nan_embedding_error(None, str(last_error)):
            if embed_nan_zero_fallback_enabled():
                dim = self._infer_embed_dim()
                logger.warning(
                    "Ollama embeddings NaN bug encountered; using zero-vector fallback (dim=%s, model=%s)",
                    dim,
                    embed_model,
                )
                return [0.0] * dim

        if last_error is not None:
            raise last_error
        raise RuntimeError("Ollama embeddings request failed with no response")

    def chat(
        self, system: str, messages: list, *, temperature: float | None = None
    ) -> str:
        rag_model = self._require_model("rag_model", "chat")
        url = f"{self.base}/chat"
        requests_module = _requests_module()
        _http_error, request_error, timeout_error = _request_exception_types(
            requests_module
        )
        chat_options = chat_options_from_env(temperature)
        timeout = chat_options.timeout
        retries = chat_options.retries
        backoff = chat_options.backoff
        jitter = chat_options.jitter
        payload = build_chat_payload(
            model=rag_model,
            system=system,
            messages=messages,
            options=chat_options,
        )

        def _sleep_backoff(attempt: int) -> None:
            if backoff <= 0:
                return
            delay = backoff * max(1, attempt)
            if jitter > 0:
                delay += random.uniform(0.0, jitter) * delay
            time.sleep(delay)

        max_attempts = max(1, retries + 1)
        fallback_needed = False
        for attempt in range(1, max_attempts + 1):
            try:
                r = requests_module.post(url, json=payload, timeout=timeout)
            except timeout_error as exc:
                if attempt < max_attempts:
                    logger.warning(
                        "Ollama chat timed out (attempt %s/%s), retrying...",
                        attempt,
                        max_attempts,
                    )
                    _sleep_backoff(attempt)
                    continue
                raise RuntimeError(
                    f"Ollama chat request timed out after {max_attempts} attempt(s): {exc}"
                ) from exc
            except request_error as exc:
                if attempt < max_attempts:
                    logger.warning(
                        "Ollama chat request failed (attempt %s/%s): %s",
                        attempt,
                        max_attempts,
                        exc,
                    )
                    _sleep_backoff(attempt)
                    continue
                raise RuntimeError(
                    f"Ollama chat request failed after {max_attempts} attempt(s): {exc}"
                ) from exc

            if r.ok:
                j = r.json()
                msg = (j.get("message") or {}).get("content")
                if isinstance(msg, str):
                    return msg
                resp = j.get("response")
                if isinstance(resp, str):
                    return resp
                if attempt < max_attempts:
                    logger.warning(
                        "Ollama chat returned empty content (attempt %s/%s), retrying...",
                        attempt,
                        max_attempts,
                    )
                    _sleep_backoff(attempt)
                    continue
                raise RuntimeError("Ollama chat: empty response")

            detail = self._extract_error_message(r)
            status = r.status_code
            if status >= 500 and attempt < max_attempts:
                logger.warning(
                    "Ollama chat HTTP %s (attempt %s/%s), retrying...",
                    status,
                    attempt,
                    max_attempts,
                )
                _sleep_backoff(attempt)
                continue
            if status == 404 and "model" in detail.lower():
                raise RuntimeError(
                    f"Ollama chat model '{rag_model}' is not installed. "
                    "Run `ollama pull` inside the Ollama container or host."
                )
            if status != 404:
                raise RuntimeError(f"Ollama chat HTTP error ({status}): {detail}")
            fallback_needed = True
            break

        if not fallback_needed:
            raise RuntimeError("Ollama chat request failed with no fallback available.")

        # Fallback for legacy endpoints that do not expose /api/chat
        prompt = (
            system + "\n\nUser:\n" + (messages[-1].get("content") if messages else "")
        )
        candidates = generate_endpoint_candidates(self.base)
        last_error: str | None = None
        for candidate in candidates:
            try:
                r2 = requests_module.post(
                    candidate,
                    json={"model": rag_model, "prompt": prompt, "stream": False},
                    timeout=timeout,
                )
                if r2.ok:
                    j2 = r2.json()
                    return str(j2.get("response", ""))
                detail = self._extract_error_message(r2)
                if r2.status_code == 404 and "model" in detail.lower():
                    raise RuntimeError(
                        f"Ollama chat model '{rag_model}' is not installed. "
                        "Run `ollama pull` inside the Ollama container or host."
                    )
                last_error = f"HTTP {r2.status_code}: {detail}"
            except request_error as exc:
                last_error = str(exc)
        raise RuntimeError(
            f"Ollama chat request failed after trying {len(candidates)} endpoints: {last_error}"
        )

    def vision_chat(
        self,
        system: str,
        prompt: str,
        *,
        image_data: str | None = None,
        messages: list | None = None,
        temperature: float | None = None,
    ) -> str:
        vision_model = self._require_model("vision_model", "vision")
        url = f"{self.base}/chat"
        requests_module = _requests_module()
        _http_error, request_error, timeout_error = _request_exception_types(
            requests_module
        )
        chat_options = chat_options_from_env(temperature)
        timeout = chat_options.timeout
        retries = chat_options.retries
        backoff = chat_options.backoff
        jitter = chat_options.jitter
        payload = {
            "model": vision_model,
            "messages": self._build_vision_messages(
                system=system,
                prompt=prompt,
                image_data=image_data,
                messages=messages,
            ),
            "stream": False,
            "options": {
                "temperature": chat_options.temperature,
                "top_p": chat_options.top_p,
                "num_predict": chat_options.num_predict,
            },
        }

        def _sleep_backoff(attempt: int) -> None:
            if backoff <= 0:
                return
            delay = backoff * max(1, attempt)
            if jitter > 0:
                delay += random.uniform(0.0, jitter) * delay
            time.sleep(delay)

        max_attempts = max(1, retries + 1)
        for attempt in range(1, max_attempts + 1):
            try:
                r = requests_module.post(url, json=payload, timeout=timeout)
            except timeout_error as exc:
                if attempt < max_attempts:
                    logger.warning(
                        "Ollama vision chat timed out (attempt %s/%s), retrying...",
                        attempt,
                        max_attempts,
                    )
                    _sleep_backoff(attempt)
                    continue
                raise RuntimeError(
                    f"Ollama vision chat request timed out after {max_attempts} attempt(s): {exc}"
                ) from exc
            except request_error as exc:
                if attempt < max_attempts:
                    logger.warning(
                        "Ollama vision chat request failed (attempt %s/%s): %s",
                        attempt,
                        max_attempts,
                        exc,
                    )
                    _sleep_backoff(attempt)
                    continue
                raise RuntimeError(
                    f"Ollama vision chat request failed after {max_attempts} attempt(s): {exc}"
                ) from exc

            if r.ok:
                j = r.json()
                msg = (j.get("message") or {}).get("content")
                if isinstance(msg, str):
                    return msg
                resp = j.get("response")
                if isinstance(resp, str):
                    return resp
                if attempt < max_attempts:
                    logger.warning(
                        "Ollama vision chat returned empty content (attempt %s/%s), retrying...",
                        attempt,
                        max_attempts,
                    )
                    _sleep_backoff(attempt)
                    continue
                raise RuntimeError("Ollama vision chat: empty response")

            detail = self._extract_error_message(r)
            status = r.status_code
            if status >= 500 and attempt < max_attempts:
                logger.warning(
                    "Ollama vision chat HTTP %s (attempt %s/%s), retrying...",
                    status,
                    attempt,
                    max_attempts,
                )
                _sleep_backoff(attempt)
                continue
            if status == 404 and "model" in detail.lower():
                raise RuntimeError(
                    f"Ollama vision model '{vision_model}' is not installed. "
                    f"Run `ollama pull {vision_model}` inside the Ollama container or host."
                )
            raise RuntimeError(f"Ollama vision chat HTTP error ({status}): {detail}")

        raise RuntimeError("Ollama vision chat request failed with no response")

    @classmethod
    def _build_vision_messages(
        cls,
        *,
        system: str,
        prompt: str,
        image_data: str | None,
        messages: list | None,
    ) -> list[dict[str, Any]]:
        if messages:
            normalized_messages = []
            has_system = False
            for message in messages:
                normalized = cls._normalize_vision_message(message)
                if normalized is None:
                    continue
                has_system = (
                    has_system or str(normalized.get("role") or "").lower() == "system"
                )
                normalized_messages.append(normalized)
            clean_image = _clean_ollama_image_data(image_data)
            if prompt or clean_image:
                user_message: dict[str, Any] = {"role": "user", "content": prompt}
                if clean_image:
                    user_message["images"] = [clean_image]
                normalized_messages.append(user_message)
            if not has_system:
                normalized_messages.insert(0, {"role": "system", "content": system})
            return normalized_messages

        user_message: dict[str, Any] = {"role": "user", "content": prompt}
        clean_image = _clean_ollama_image_data(image_data)
        if clean_image:
            user_message["images"] = [clean_image]
        return [{"role": "system", "content": system}, user_message]

    @staticmethod
    def _normalize_vision_message(message: object) -> dict[str, Any] | None:
        if not isinstance(message, dict):
            return None

        role = str(message.get("role") or "user")
        content = message.get("content", "")
        images: list[str] = []
        text_parts: list[str] = []

        if isinstance(content, list):
            for part in content:
                if not isinstance(part, dict):
                    text_parts.append(str(part))
                    continue
                part_type = str(part.get("type") or "").lower()
                if part_type == "text":
                    text_parts.append(str(part.get("text") or ""))
                    continue
                if part_type in ("image_url", "input_image"):
                    image_url = part.get("image_url") or part.get("image")
                    if isinstance(image_url, dict):
                        image_url = image_url.get("url")
                    clean_image = _clean_ollama_image_data(image_url)
                    if clean_image:
                        images.append(clean_image)
                    continue
                clean_image = _clean_ollama_image_data(part.get("image_data"))
                if clean_image:
                    images.append(clean_image)
        else:
            text_parts.append(str(content or ""))

        raw_images = message.get("images")
        if isinstance(raw_images, list):
            for item in raw_images:
                clean_image = _clean_ollama_image_data(item)
                if clean_image:
                    images.append(clean_image)
        else:
            clean_image = _clean_ollama_image_data(raw_images)
            if clean_image:
                images.append(clean_image)

        normalized: dict[str, Any] = {
            "role": role,
            "content": "\n".join(part for part in text_parts if part).strip(),
        }
        if images:
            normalized["images"] = images
        return normalized

    @staticmethod
    def _extract_error_message(resp: Any) -> str:
        return extract_error_message(resp)
