"""The public direct-text request contract.

The service validates the stable request shapes and limits for every caller.
The management UI and direct API clients use the same contract.
"""

from __future__ import annotations

from collections.abc import Mapping
import re
from typing import Any, Literal
from urllib.parse import urlsplit

from pydantic import BaseModel, ConfigDict, Field, field_validator

from hawki_rag_contracts.source_metadata import (
    SourceMetadata,
    metadata_digest,
    validate_source_metadata,
)

from .identity import fingerprint
from .settings import MAX_TEXT_CHARACTERS

_IDENTIFIER = re.compile(r"[A-Za-z0-9][A-Za-z0-9._:-]*")
_ENVELOPE_FIELDS = frozenset(
    {
        "external_document_id",
        "dataset_id",
        "text",
        "content_format",
        "display_name",
        "source_url",
        "metadata",
    }
)


class TextContent(BaseModel):
    """Validation shared by managed inline text and historical text requests."""

    model_config = ConfigDict(extra="forbid")

    external_document_id: str | None = Field(default=None, min_length=1, max_length=191)
    dataset_id: str | None = Field(default=None, min_length=1, max_length=160)
    text: str = Field(min_length=1, max_length=MAX_TEXT_CHARACTERS)
    content_format: Literal["plain_text", "markdown"] = "plain_text"
    display_name: str | None = Field(default=None, max_length=255)
    source_url: str | None = Field(default=None, max_length=2048)
    metadata: SourceMetadata = Field(default_factory=dict)

    @field_validator("display_name", "source_url")
    @classmethod
    def validate_unicode(cls, value: str | None) -> str | None:
        if value is not None:
            value.encode("utf-8")
        return value

    @field_validator("external_document_id")
    @classmethod
    def validate_external_document_id(cls, value: str | None) -> str | None:
        if value is not None and _IDENTIFIER.fullmatch(value) is None:
            raise ValueError(
                "external_document_id must match [A-Za-z0-9][A-Za-z0-9._:-]*"
            )
        return value

    @field_validator("dataset_id")
    @classmethod
    def validate_dataset_id(cls, value: str | None) -> str | None:
        if value is not None and _IDENTIFIER.fullmatch(value) is None:
            raise ValueError("dataset_id must match [A-Za-z0-9][A-Za-z0-9._:-]*")
        return value

    @field_validator("text")
    @classmethod
    def validate_text(cls, value: str) -> str:
        value.encode("utf-8")
        if not value.strip():
            raise ValueError("text must not be blank")
        return value

    @field_validator("source_url")
    @classmethod
    def validate_source_url(cls, value: str | None) -> str | None:
        if value is None:
            return None
        parts = urlsplit(value)
        if parts.scheme not in {"http", "https"} or not parts.netloc:
            raise ValueError("source_url must be an http or https URL")
        return value


class TextIngestionRequest(TextContent):
    """Request contract used by the historical text workflow."""

    external_document_id: str = Field(min_length=1, max_length=191)
    dataset_id: str = Field(min_length=1, max_length=160)
    content_format: Literal["plain_text", "markdown"]

    def fingerprint_values(self) -> dict[str, Any]:
        """Return the fields the legacy version-2 fingerprint covers."""
        return {
            "external_document_id": self.external_document_id,
            "dataset_id": self.dataset_id,
            "text": self.text,
            "content_format": self.content_format,
            "display_name": self.display_name,
            "source_url": self.source_url,
            "metadata": self.metadata,
        }

    def fingerprint(self) -> str:
        return fingerprint(self.fingerprint_values())

    def matches_operation(self, row: Mapping[str, Any], request_hash: str) -> bool:
        """Replay only the same logical request, including historical number encoding."""
        previous = row["request_metadata"].get("metadata")
        if previous is None or previous == []:
            previous = {}
        try:
            normalized = validate_source_metadata(previous)
        except ValueError:
            return False
        if metadata_digest(normalized) != metadata_digest(self.metadata):
            return False
        if row["fingerprint"] == request_hash:
            return True
        # Old fingerprints used the submitted spelling for integral floats.
        return row["fingerprint"] == fingerprint(
            {**self.fingerprint_values(), "metadata": previous}
        )

    def request_metadata(self, idempotency_key: str) -> dict[str, Any]:
        """Return the non-content metadata persisted with the operation."""
        return {
            "external_document_id": self.external_document_id,
            "dataset_id": self.dataset_id,
            "text": None,
            "content_format": self.content_format,
            "display_name": self.display_name,
            "source_url": self.source_url,
            "metadata": self.metadata,
            "idempotency_key": idempotency_key,
            "graph": False,
        }


def validate_idempotency_key(value: str | None) -> str:
    """Return a valid idempotency key, refusing absence and bad shapes."""
    if value is None:
        raise ValueError("The idempotency key field is required.")
    if not isinstance(value, str) or not value:
        raise ValueError("The idempotency key field is required.")
    if len(value) > 191 or _IDENTIFIER.fullmatch(value) is None:
        raise ValueError("The idempotency key format is invalid.")
    return value


ENVELOPE_FIELDS = _ENVELOPE_FIELDS

__all__ = [
    "ENVELOPE_FIELDS",
    "TextContent",
    "TextIngestionRequest",
    "validate_idempotency_key",
]
