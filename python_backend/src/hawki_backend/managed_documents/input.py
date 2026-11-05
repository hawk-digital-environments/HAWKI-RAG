"""Public multipart normalization, matching Laravel's managed request parser."""

import hashlib
import json
from datetime import datetime, timezone
from pathlib import PurePosixPath
from typing import Any
from urllib.parse import urlsplit

from pydantic import BaseModel, ConfigDict, Field, field_validator

MAX_FILE_BYTES = 100 * 1024 * 1024
DEFAULT_EXTENSIONS = (
    "pdf,doc,docx,ppt,pptx,xls,xlsx,jpg,jpeg,png,bmp,tif,tiff,gif,webp,txt,md"
)


class ManagedError(Exception):
    def __init__(self, status: int, message: str, details: dict | None = None) -> None:
        self.status = status
        self.message = message
        self.details = details or {}
        super().__init__(message)


def digest(value: Any) -> str:
    return hashlib.sha256(
        json.dumps(
            value, sort_keys=True, separators=(",", ":"), ensure_ascii=False
        ).encode()
    ).hexdigest()


def boolean(value: Any) -> bool:
    return str(value).lower() in {"1", "true", "on", "yes"}


class ManagedInput(BaseModel):
    model_config = ConfigDict(extra="ignore", str_strip_whitespace=True)
    dataset_id: str | None = Field(
        default=None, pattern=r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$"
    )
    display_name: str | None = Field(default=None, max_length=255)
    source_url: str | None = Field(default=None, max_length=2048)
    source_updated_at: datetime | None = None
    source_checksum_sha256: str | None = Field(
        default=None, pattern=r"^[a-fA-F0-9]{64}$"
    )
    graph: bool = False
    force: bool = False
    metadata_json: dict | list | None = None
    converter_mode: str = Field(default="native", pattern=r"^(native|custom)$")
    converter_url: str | None = Field(default=None, max_length=2048)
    converter_token: str | None = Field(default=None, max_length=4096, repr=False)
    converter_start_path: str = Field(default="/extract", max_length=160)

    @field_validator(
        "dataset_id",
        "display_name",
        "source_url",
        "source_updated_at",
        "source_checksum_sha256",
        "converter_url",
        "converter_token",
        mode="before",
    )
    @classmethod
    def empty_nullable(cls, value: Any) -> Any:
        return None if value == "" else value

    @field_validator("graph", "force", mode="before")
    @classmethod
    def parse_boolean(cls, value: Any) -> bool:
        return boolean(value)

    @field_validator("metadata_json", mode="before")
    @classmethod
    def parse_metadata(cls, value: Any) -> Any:
        if value in (None, ""):
            return None
        return json.loads(value) if isinstance(value, str) else value

    @field_validator("source_url", "converter_url")
    @classmethod
    def valid_url(cls, value: str | None) -> str | None:
        if value is not None:
            parts = urlsplit(value)
            if (
                not parts.scheme
                or not value.startswith(parts.scheme + "://")
                or not parts.hostname
            ):
                raise ValueError("A valid URL is required")
        return value

    @field_validator("source_checksum_sha256")
    @classmethod
    def checksum(cls, value: str | None) -> str | None:
        return value.lower() if value else None

    @field_validator("source_updated_at")
    @classmethod
    def timestamp(cls, value: datetime | None) -> datetime | None:
        return (
            value.replace(tzinfo=timezone.utc)
            if value and value.tzinfo is None
            else value.astimezone(timezone.utc)
            if value
            else None
        )

    def canonical(self) -> dict:
        return self.model_dump(mode="json", exclude_unset=True)

    def attributes(self, filename: str, checksum: str) -> dict:
        return {
            "display_name": self.display_name or filename,
            "original_filename": filename,
            "source_url": self.source_url,
            "source_updated_at": self.source_updated_at.isoformat()
            if self.source_updated_at
            else None,
            "source_checksum_sha256": self.source_checksum_sha256 or checksum,
            "graph_enabled": self.graph,
            "metadata_json": self.metadata_json if self.metadata_json else [],
        }


def extension(filename: str, extensions: tuple[str, ...], custom: bool) -> str:
    # PHP's client extension is authoritative, with a MIME-derived fallback only
    # for extensionless names. The BFF sends that fallback as the upload filename.
    name = PurePosixPath(filename.replace("\\", "/")).name
    value = name.rsplit(".", 1)[1].lower() if "." in name else ""
    if not value or (not custom and value not in extensions):
        raise ManagedError(
            422,
            "This file type is not accepted by RAGAnything native ingestion. Enable Custom converter for special formats."
            if not custom
            else "Custom converter uploads still need a file extension so the pipeline can preserve type metadata.",
            {"accepted_extensions": list(extensions)},
        )
    return value
