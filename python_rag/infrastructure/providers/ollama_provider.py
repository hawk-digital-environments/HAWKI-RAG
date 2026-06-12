import logging
import math
import os
import random
import time
from typing import Any

from infrastructure.providers.ollama_helpers import (
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
from common.optional_imports import import_required_module


logger = logging.getLogger(__name__)


def _requests_module() -> Any:
    return import_required_module(
        "requests",
        install_hint="Install python_rag/requirements.txt to use the Ollama provider.",
    )


def _request_exception_types(requests_module: Any | None = None) -> tuple[type[BaseException], type[BaseException], type[BaseException]]:
    module = requests_module or _requests_module()
    exceptions = module.exceptions
    return exceptions.HTTPError, exceptions.RequestException, exceptions.Timeout


class OllamaProvider:
    def __init__(self) -> None:
        base = os.environ.get("OLLAMA_API_URL", "http://ollama:11434/api").rstrip("/")
        self.base = base
        # Recommended multilingual embedding default: BAAI/bge-m3 (Ollama must have the model pulled)
        self.embed_model = os.environ.get("OLLAMA_EMBED_MODEL", "bge-m3")
        # LLM used for chat/RAG; override with OLLAMA_RAG_MODEL / OLLAMA_TEXT_MODEL if desired
        self.rag_model = os.environ.get(
            "OLLAMA_RAG_MODEL",
            os.environ.get("OLLAMA_TEXT_MODEL", "llama3:8b"),
        )
        self._last_embed_dim: int | None = None

    def _infer_embed_dim(self) -> int:
        return infer_embedding_dim(self.embed_model, self._last_embed_dim)

    @staticmethod
    def _clean_embedding_text(text: str) -> str:
        return clean_embedding_text(text)

    @staticmethod
    def _is_ollama_nan_embedding_error(status: int | None, message: str) -> bool:
        return is_ollama_nan_embedding_error(status, message)

    def embed(self, text: str) -> list[float]:
        url = f"{self.base}/embeddings"
        timeout = embedding_timeout_from_env()
        requests_module = _requests_module()
        http_error, request_error, _timeout_error = _request_exception_types(requests_module)

        prompts = [str(text or "")]
        cleaned_prompt = self._clean_embedding_text(text)
        if cleaned_prompt != prompts[0]:
            prompts.append(cleaned_prompt)

        last_error: RuntimeError | None = None
        for attempt_idx, prompt in enumerate(prompts, start=1):
            try:
                r = requests_module.post(url, json={"model": self.embed_model, "prompt": prompt}, timeout=timeout)
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
                        detail = payload.get("error") or payload.get("message") or str(payload)
                    else:
                        detail = resp.text
                message = detail or str(exc)
                if status == 404 and "not" in message.lower() and "model" in message.lower():
                    raise RuntimeError(
                        f"Ollama embeddings model '{self.embed_model}' is not installed. "
                        f"Run `ollama pull {self.embed_model}` inside the Ollama container or host."
                    ) from exc
                if (
                    self._is_ollama_nan_embedding_error(status, message)
                    and attempt_idx < len(prompts)
                ):
                    logger.warning(
                        "Ollama embeddings returned NaN serialization error; retrying with sanitized prompt (attempt %s/%s)",
                        attempt_idx,
                        len(prompts),
                    )
                    continue
                last_error = RuntimeError(f"Ollama embeddings HTTP error ({status}): {message}")
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
                    self.embed_model,
                )
                return [0.0] * dim

        if last_error is not None:
            raise last_error
        raise RuntimeError("Ollama embeddings request failed with no response")

    def chat(self, system: str, messages: list, *, temperature: float | None = None) -> str:
        url = f"{self.base}/chat"
        requests_module = _requests_module()
        _http_error, request_error, timeout_error = _request_exception_types(requests_module)
        chat_options = chat_options_from_env(temperature)
        timeout = chat_options.timeout
        retries = chat_options.retries
        backoff = chat_options.backoff
        jitter = chat_options.jitter
        payload = build_chat_payload(
            model=self.rag_model,
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
                    logger.warning("Ollama chat timed out (attempt %s/%s), retrying...", attempt, max_attempts)
                    _sleep_backoff(attempt)
                    continue
                raise RuntimeError(
                    f"Ollama chat request timed out after {max_attempts} attempt(s): {exc}"
                ) from exc
            except request_error as exc:
                if attempt < max_attempts:
                    logger.warning("Ollama chat request failed (attempt %s/%s): %s", attempt, max_attempts, exc)
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
                    logger.warning("Ollama chat returned empty content (attempt %s/%s), retrying...", attempt, max_attempts)
                    _sleep_backoff(attempt)
                    continue
                raise RuntimeError("Ollama chat: empty response")

            detail = self._extract_error_message(r)
            status = r.status_code
            if status >= 500 and attempt < max_attempts:
                logger.warning("Ollama chat HTTP %s (attempt %s/%s), retrying...", status, attempt, max_attempts)
                _sleep_backoff(attempt)
                continue
            if status == 404 and "model" in detail.lower():
                raise RuntimeError(
                    f"Ollama chat model '{self.rag_model}' is not installed. "
                    "Run `ollama pull` inside the Ollama container or host."
                )
            if status != 404:
                raise RuntimeError(f"Ollama chat HTTP error ({status}): {detail}")
            fallback_needed = True
            break

        if not fallback_needed:
            raise RuntimeError("Ollama chat request failed with no fallback available.")

        # Fallback for legacy endpoints that do not expose /api/chat
        prompt = system + "\n\nUser:\n" + (messages[-1].get("content") if messages else "")
        candidates = generate_endpoint_candidates(self.base)
        last_error: str | None = None
        for candidate in candidates:
            try:
                r2 = requests_module.post(
                    candidate,
                    json={"model": self.rag_model, "prompt": prompt, "stream": False},
                    timeout=timeout,
                )
                if r2.ok:
                    j2 = r2.json()
                    return str(j2.get("response", ""))
                detail = self._extract_error_message(r2)
                if r2.status_code == 404 and "model" in detail.lower():
                    raise RuntimeError(
                        f"Ollama chat model '{self.rag_model}' is not installed. "
                        "Run `ollama pull` inside the Ollama container or host."
                )
                last_error = f"HTTP {r2.status_code}: {detail}"
            except request_error as exc:
                last_error = str(exc)
        raise RuntimeError(
            f"Ollama chat request failed after trying {len(candidates)} endpoints: {last_error}"
        )

    @staticmethod
    def _extract_error_message(resp: Any) -> str:
        return extract_error_message(resp)
