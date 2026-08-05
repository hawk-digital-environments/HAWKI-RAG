"""Environment-backed reranker settings."""

from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class RerankerSettings:
    model_name: str = "mixedbread-ai/mxbai-rerank-base-v1"
    host: str = "0.0.0.0"
    port: int = 8000


def load_settings() -> RerankerSettings:
    return RerankerSettings(
        model_name=os.getenv(
            "HAWKI_RERANKER_MODEL",
            "mixedbread-ai/mxbai-rerank-base-v1",
        ).strip(),
        host=os.getenv("HAWKI_RERANKER_HOST", "0.0.0.0").strip(),
        port=int(os.getenv("HAWKI_RERANKER_PORT", "8000")),
    )


__all__ = ["RerankerSettings", "load_settings"]
