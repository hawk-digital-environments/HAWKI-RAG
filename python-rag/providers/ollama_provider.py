import os
import requests
from requests import HTTPError, RequestException
from typing import List


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

    def chat(self, system: str, messages: list) -> str:
        url = f"{self.base}/chat"
        payload = {
            "model": self.rag_model,
            "messages": [{"role": "system", "content": system}] + messages,
            "stream": False,
            "options": {"temperature": 0.3, "top_p": 0.9, "num_predict": 900},
        }
        r = requests.post(url, json=payload, timeout=120)
        if r.ok:
            j = r.json()
            msg = (j.get("message") or {}).get("content")
            if isinstance(msg, str):
                return msg
            resp = j.get("response")
            if isinstance(resp, str):
                return resp
        # Fallback to /generate
        url = f"{self.base}/generate"
        prompt = system + "\n\nUser:\n" + (messages[-1].get("content") if messages else "")
        r2 = requests.post(url, json={"model": self.rag_model, "prompt": prompt, "stream": False}, timeout=120)
        r2.raise_for_status()
        j2 = r2.json()
        return str(j2.get("response", ""))
