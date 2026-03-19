from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any

from .backend_runtime import BackendRuntime

logger = logging.getLogger(__name__)


@dataclass(slots=True)
class Embedder:
    """Small wrapper that reuses the backend provider for offline embedding-only tasks."""
    model_key: str
    model_config: dict[str, Any]
    runtime: BackendRuntime
    timeout_seconds: int = 60

    def embed_texts(self, texts: list[str]) -> list[list[float]]:
        """Embed a list of texts with the same model wiring used by the backend."""
        logger.info("embedder.embed_texts start model_key=%s texts=%s", self.model_key, len(texts))
        try:
            with self.runtime.model_context(self.model_key):
                provider = self.runtime.get_provider(str(self.model_config["provider"]))
                if hasattr(provider, "embed_model"):
                    provider.embed_model = str(self.model_config["model_name"])
                vectors = [provider.embed(text) for text in texts]
                logger.info(
                    "embedder.embed_texts success model_key=%s vectors=%s dim=%s",
                    self.model_key,
                    len(vectors),
                    len(vectors[0]) if vectors else 0,
                )
                return vectors
        except Exception as exc:
            logger.exception("embedder.embed_texts failed model_key=%s error=%s", self.model_key, exc)
            raise
