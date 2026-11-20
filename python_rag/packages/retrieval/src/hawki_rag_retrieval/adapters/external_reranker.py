"""The local HAWKI Cohere-compatible contract used by external reranking."""

import os
from dataclasses import dataclass, field
from collections.abc import Mapping
from urllib.parse import urlsplit

CONTRACT = "hawki-cohere-v1"


@dataclass(frozen=True)
class ExternalRerankerSettings:
    url: str
    api_key: str = field(repr=False)
    model: str = ""
    dialect: str = CONTRACT

    @property
    def headers(self) -> dict[str, str]:
        return {
            "Content-Type": "application/json",
            **({"Authorization": "Bearer " + self.api_key} if self.api_key else {}),
        }

    def body(self, query: str, documents: list[str]) -> dict:
        return {
            "query": query,
            "documents": documents,
            **({"model": self.model} if self.model else {}),
        }


def load_external_reranker_settings(
    env: Mapping[str, str] | None = None,
) -> ExternalRerankerSettings:
    source = os.environ if env is None else env
    settings = ExternalRerankerSettings(
        url=source.get("RERANKER_API_URL", "").strip(),
        api_key=source.get("RERANKER_API_KEY", "").strip(),
        model=source.get("RERANKER_MODEL", "").strip(),
        dialect=source.get("RERANKER_API_DIALECT", CONTRACT).strip(),
    )
    parts = urlsplit(settings.url)
    if (
        parts.scheme not in {"http", "https"}
        or not parts.hostname
        or parts.username
        or parts.password
        or parts.query
        or parts.fragment
        or settings.dialect != CONTRACT
    ):
        raise ValueError("Unsupported external reranker contract")
    return settings
