import logging
import os
import random
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

    def embed(self, text: str) -> List[float]:
        url = f"{self.base}/embeddings"
        try:
            r = requests.post(url, json={"model": self.embed_model, "prompt": text}, timeout=60)
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
            raise RuntimeError(f"Ollama embeddings HTTP error ({status}): {message}") from exc
        except RequestException as exc:
            raise RuntimeError(f"Ollama embeddings request failed: {exc}") from exc

        data = r.json()
        vec = data.get("embedding")
        if not isinstance(vec, list):
            raise RuntimeError("Ollama embeddings: unexpected response")
        return [float(x) for x in vec]

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
