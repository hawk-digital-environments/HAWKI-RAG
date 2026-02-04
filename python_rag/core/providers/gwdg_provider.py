import os
import requests
from typing import List


class GWDGProvider:
    def __init__(self) -> None:
        self.base = os.environ.get("GWDG_API_URL", "").rstrip("/")
        self.key = os.environ.get("GWDG_API_KEY", "")
        self.rag_model = os.environ.get("GWDG_RAG_MODEL", "llama-3.3-70b-instruct")
        # Recommended OpenAI-compatible embedding default: text-embedding-3-large
        self.embed_model = os.environ.get("GWDG_EMBED_MODEL", "text-embedding-3-large")

    def _headers(self):
        return {"Authorization": f"Bearer {self.key}", "Content-Type": "application/json"}

    def embed(self, text: str) -> List[float]:
        if not self.base or not self.key:
            raise RuntimeError("GWDG embeddings require GWDG_API_URL and GWDG_API_KEY")
        url = f"{self.base}/embeddings"
        r = requests.post(url, headers=self._headers(), json={"model": self.embed_model, "input": text}, timeout=60)
        r.raise_for_status()
        j = r.json()
        vec = ((j.get("data") or [{}])[0] or {}).get("embedding")
        if not isinstance(vec, list):
            raise RuntimeError("GWDG embeddings: unexpected response")
        return [float(x) for x in vec]

    def chat(self, system: str, messages: list, *, temperature: float | None = None) -> str:
        if not self.base or not self.key:
            return "I apologize, but the chat server is not configured."
        url = f"{self.base}/chat/completions"
        if temperature is None:
            env_temp = os.environ.get("GWDG_TEMPERATURE", "").strip()
            if env_temp:
                try:
                    temperature = float(env_temp)
                except ValueError:
                    temperature = None
        if temperature is None:
            temperature = 0.3
        payload = {
            "model": self.rag_model,
            "messages": [{"role": "system", "content": system}] + messages,
            "temperature": temperature,
            "top_p": 0.9,
        }
        r = requests.post(url, headers=self._headers(), json=payload, timeout=90)
        r.raise_for_status()
        j = r.json()
        try:
            return str(j["choices"][0]["message"]["content"]).strip()
        except Exception:
            return "I apologize, but I received an unexpected response format."
