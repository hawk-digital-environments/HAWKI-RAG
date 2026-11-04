"""The public direct-text request contract.

The limits and the accepted shapes mirror ``IngestTextRequest`` exactly. The
service, not the compatibility BFF, is the authoritative validator, so a
caller cannot widen the contract by reaching Python directly.
"""

from __future__ import annotations

import re
from typing import Any, Literal
from urllib.parse import urlsplit

from pydantic import BaseModel, ConfigDict, Field, field_validator

from .identity import fingerprint, metadata_bytes
from .settings import MAX_METADATA_BYTES, MAX_TEXT_CHARACTERS

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


class TextIngestionRequest(BaseModel):
    """Validated public direct-text input."""

    model_config = ConfigDict(extra="forbid")

    external_document_id: str = Field(min_length=1, max_length=191)
    dataset_id: str = Field(min_length=1, max_length=160)
    text: str = Field(min_length=1, max_length=MAX_TEXT_CHARACTERS)
    content_format: Literal["plain_text", "markdown"]
    display_name: str | None = Field(default=None, max_length=255)
    source_url: str | None = Field(default=None, max_length=2048)
    metadata: Any = None

    @field_validator("display_name", "source_url")
    @classmethod
    def validate_unicode(cls, value: str | None) -> str | None:
        if value is not None:
            value.encode("utf-8")
        return value

    @field_validator("external_document_id")
    @classmethod
    def validate_external_document_id(cls, value: str) -> str:
        if _IDENTIFIER.fullmatch(value) is None:
            raise ValueError(
                "external_document_id must match [A-Za-z0-9][A-Za-z0-9._:-]*"
            )
        return value

    @field_validator("dataset_id")
    @classmethod
    def validate_dataset_id(cls, value: str) -> str:
        if _IDENTIFIER.fullmatch(value) is None:
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

    @field_validator("metadata")
    @classmethod
    def validate_metadata(cls, value: Any) -> dict[str, Any]:
        if value is None:
            return {}
        if isinstance(value, list):
            if value:
                raise ValueError("metadata must be a JSON object")
            return {}
        if not isinstance(value, dict):
            raise ValueError("metadata must be a JSON object")
        if value and list(value) == [str(index) for index in range(len(value))]:
            raise ValueError("metadata must be a JSON object")
        if metadata_bytes(value) > MAX_METADATA_BYTES:
            raise ValueError("metadata is too large")
        return value

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
    "TextIngestionRequest",
    "validate_idempotency_key",
]
