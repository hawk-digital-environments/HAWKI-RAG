"""Laravel-derived authorization scope accepted by the query service."""

from __future__ import annotations

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    StrictBool,
    field_validator,
    model_validator,
)

EXCLUDED_DOC_IDS_LIMIT = 1000


class AuthorizedQueryScope(BaseModel):
    """Storage scope derived by Laravel after dataset authorization.

    ``excluded_doc_ids`` carries the bridge document IDs of managed documents
    whose deletion is pending verification. Laravel derives it from durable
    deletion operations; callers cannot influence it. Retrieval must treat
    those documents as already deleted until sink cleanup is verified.
    """

    model_config = ConfigDict(extra="forbid", frozen=True, str_strip_whitespace=True)

    dataset_id: str = Field(min_length=1, max_length=191)
    qdrant_collection: str = Field(min_length=1, max_length=191)
    neo4j_namespace: str | None = Field(default=None, max_length=191)
    embedding_provider: str = Field(
        min_length=1,
        max_length=80,
        pattern=r"^[A-Za-z0-9][A-Za-z0-9._-]*$",
    )
    embedding_model: str = Field(
        min_length=1,
        max_length=160,
        pattern=r"^[A-Za-z0-9][A-Za-z0-9._:/-]*$",
    )
    graph_enabled: StrictBool = False
    excluded_doc_ids: list[str] = Field(
        default_factory=list, max_length=EXCLUDED_DOC_IDS_LIMIT
    )

    @field_validator("excluded_doc_ids", mode="before")
    @classmethod
    def normalize_excluded_doc_ids(cls, value: list[str] | None) -> list[str]:
        """Strip, drop empties, and de-duplicate while preserving order."""

        if value is None:
            return []
        cleaned: list[str] = []
        for item in value:
            text = str(item).strip()
            if text and text not in cleaned:
                cleaned.append(text)
        return cleaned

    @field_validator("excluded_doc_ids")
    @classmethod
    def bound_excluded_doc_ids(cls, value: list[str]) -> list[str]:
        if len(value) > EXCLUDED_DOC_IDS_LIMIT:
            raise ValueError(
                f"excluded_doc_ids must not exceed {EXCLUDED_DOC_IDS_LIMIT} entries"
            )
        return value

    @field_validator("embedding_provider")
    @classmethod
    def normalize_embedding_provider(cls, value: str) -> str:
        """Use the canonical lower-case provider key."""

        return value.lower()

    @model_validator(mode="after")
    def require_graph_namespace(self) -> "AuthorizedQueryScope":
        """Require the server-derived namespace before graph reads can run."""

        if self.graph_enabled and not self.neo4j_namespace:
            raise ValueError(
                "neo4j_namespace is required when graph retrieval is enabled"
            )
        return self


__all__ = ["AuthorizedQueryScope"]
