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


class AuthorizedQueryScope(BaseModel):
    """Storage scope derived by Laravel after dataset authorization."""

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
