import logging
import math
import os
import random
import re
import time
import requests
from requests import HTTPError, RequestException, Timeout
from typing import List


logger = logging.getLogger(__name__)


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
        name = str(self.embed_model or "").lower()
        if self._last_embed_dim and self._last_embed_dim > 0:
            return self._last_embed_dim
        if "bge-m3" in name:
            return 1024
        if "text-embedding-3-large" in name:
            return 3072
        if "text-embedding-3-small" in name:
            return 1536
        return 1024

    @staticmethod
    def _clean_embedding_text(text: str) -> str:
        # Remove control chars that often come from noisy crawled content/UI fragments.
        cleaned = "".join(
            ch for ch in str(text or "") if ch in ("\n", "\r", "\t") or ord(ch) >= 32
        )
        cleaned = cleaned.encode("utf-8", errors="ignore").decode("utf-8", errors="ignore")
        cleaned = re.sub(r"[ \t]+", " ", cleaned)
        cleaned = re.sub(r"\n{3,}", "\n\n", cleaned).strip()
        max_chars_env = os.environ.get("OLLAMA_EMBED_MAX_CHARS", "").strip()
        try:
            max_chars = int(max_chars_env) if max_chars_env else 4000
        except ValueError:
            max_chars = 4000
        if max_chars > 0:
            cleaned = cleaned[:max_chars]
        return cleaned or " "

    @staticmethod
    def _is_ollama_nan_embedding_error(status: int | None, message: str) -> bool:
        msg = (message or "").lower()
        if "unsupported value: nan" not in msg:
            return False
        return status in (None, 500)

    def embed(self, text: str) -> List[float]:
        url = f"{self.base}/embeddings"
        timeout_env = os.environ.get("OLLAMA_EMBED_TIMEOUT", "").strip()
        try:
            timeout = float(timeout_env) if timeout_env else 60.0
        except ValueError:
            timeout = 60.0

        prompts = [str(text or "")]
        cleaned_prompt = self._clean_embedding_text(text)
        if cleaned_prompt != prompts[0]:
            prompts.append(cleaned_prompt)

        last_error: RuntimeError | None = None
        for attempt_idx, prompt in enumerate(prompts, start=1):
            try:
                r = requests.post(url, json={"model": self.embed_model, "prompt": prompt}, timeout=timeout)
                r.raise_for_status()
            except HTTPError as exc:
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
            except RequestException as exc:
                last_error = RuntimeError(f"Ollama embeddings request failed: {exc}")
            else:
                data = r.json()
                vec = data.get("embedding")
                if not isinstance(vec, list):
                    last_error = RuntimeError("Ollama embeddings: unexpected response")
                    continue
                out: List[float] = []
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
            fallback_env = os.environ.get("OLLAMA_EMBED_NAN_ZERO_FALLBACK", "true").strip().lower()
            if fallback_env in ("1", "true", "yes", "on"):
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
        timeout_env = os.environ.get("OLLAMA_CHAT_TIMEOUT", "").strip()
        try:
            timeout = float(timeout_env) if timeout_env else 120.0
        except ValueError:
            timeout = 120.0
        retries_env = os.environ.get("OLLAMA_CHAT_RETRIES", "").strip()
        try:
            retries = int(retries_env) if retries_env else 0
        except ValueError:
            retries = 0
        retries = max(0, retries)
        backoff_env = os.environ.get("OLLAMA_CHAT_BACKOFF", "").strip()
        try:
            backoff = float(backoff_env) if backoff_env else 1.5
        except ValueError:
            backoff = 1.5
        backoff = max(0.0, backoff)
        jitter_env = os.environ.get("OLLAMA_CHAT_JITTER", "").strip()
        try:
            jitter = float(jitter_env) if jitter_env else 0.2
        except ValueError:
            jitter = 0.2
        jitter = max(0.0, jitter)
        if temperature is None:
            env_temp = os.environ.get("OLLAMA_TEMPERATURE", "").strip()
            if env_temp:
                try:
                    temperature = float(env_temp)
                except ValueError:
                    temperature = None
        if temperature is None:
            temperature = 0.3
        num_predict_env = os.environ.get("OLLAMA_NUM_PREDICT", "").strip()
        try:
            num_predict = int(num_predict_env) if num_predict_env else 900
        except ValueError:
            num_predict = 900
        top_p_env = os.environ.get("OLLAMA_TOP_P", "").strip()
        try:
            top_p = float(top_p_env) if top_p_env else 0.9
        except ValueError:
            top_p = 0.9
        payload = {
            "model": self.rag_model,
            "messages": [{"role": "system", "content": system}] + messages,
            "stream": False,
            "options": {"temperature": temperature, "top_p": top_p, "num_predict": num_predict},
        }

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
                r = requests.post(url, json=payload, timeout=timeout)
            except Timeout as exc:
                if attempt < max_attempts:
                    logger.warning("Ollama chat timed out (attempt %s/%s), retrying...", attempt, max_attempts)
                    _sleep_backoff(attempt)
                    continue
                raise RuntimeError(
                    f"Ollama chat request timed out after {max_attempts} attempt(s): {exc}"
                ) from exc
            except RequestException as exc:
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
        candidates = [f"{self.base}/generate"]
        if self.base.endswith("/api"):
            base_no_api = self.base[: -4]
            candidates.append(f"{base_no_api}/generate")
            candidates.append(f"{base_no_api}/api/generate")
        last_error: str | None = None
        for candidate in candidates:
            try:
                r2 = requests.post(
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
            except RequestException as exc:
                last_error = str(exc)
        raise RuntimeError(
            f"Ollama chat request failed after trying {len(candidates)} endpoints: {last_error}"
        )

    @staticmethod
    def _extract_error_message(resp: requests.Response) -> str:
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
