"""
Shared pipeline event contracts for cross-service RabbitMQ ingestion flow.
"""
from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Any, Dict, Literal, Optional
from uuid import UUID

from pydantic import BaseModel, Field, ValidationError

try:  # pragma: no cover - pydantic v2 only
    from pydantic import ConfigDict
except Exception:  # pragma: no cover - pydantic v1 fallback
    ConfigDict = None


class _EventModel(BaseModel):
    # Accept forward-compatible extra fields from upstream publishers/routers.
    if ConfigDict is not None:  # pragma: no branch
        model_config = ConfigDict(extra="allow")
    else:  # pragma: no cover - pydantic v1 fallback
        class Config:
            extra = "allow"


class DocumentConvertedEvent(_EventModel):
    event_id: UUID
    job_id: UUID
    parent_event_id: UUID
    schema_version: str
    event_type: Literal["convert.document.completed"]
    source: str
    original_url: str
    original_path: str
    original_relative_path: Optional[str] = None
    converted_path: str
    converted_relative_path: str
    output_format: Literal["markdown"]
    converter_name: str
    converter_version: Optional[str] = None
    input_checksum_sha256: Optional[str] = None
    output_checksum_sha256: Optional[str] = None
    converted_at: datetime
    trace_id: Optional[str] = None
    payload: Optional[Dict[str, Any]] = None


class PipelineFailedEvent(_EventModel):
    event_id: UUID
    job_id: UUID
    parent_event_id: Optional[UUID] = None
    schema_version: str
    event_type: Literal["pipeline.failed"]
    failed_stage: Literal["rag_ingestion"]
    source: Literal["hawki-rag"]
    error_type: str
    error_message: str
    retry_count: int = Field(ge=0)
    max_retries: int = Field(ge=0)
    original_event_type: str
    original_event_payload: Dict[str, Any]
    failed_at: datetime
    trace_id: Optional[str] = None


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


def validate_document_converted_event(payload: Dict[str, Any]) -> DocumentConvertedEvent:
    if hasattr(DocumentConvertedEvent, "model_validate"):
        return DocumentConvertedEvent.model_validate(payload)  # pydantic v2
    return DocumentConvertedEvent.parse_obj(payload)  # pragma: no cover - v1 fallback


def parse_document_converted_event(message: bytes | str | Dict[str, Any]) -> DocumentConvertedEvent:
    if isinstance(message, dict):
        payload = message
    elif isinstance(message, bytes):
        payload = json.loads(message.decode("utf-8"))
    else:
        payload = json.loads(str(message))
    return validate_document_converted_event(payload)


def model_to_dict(model: BaseModel) -> Dict[str, Any]:
    if hasattr(model, "model_dump"):
        return model.model_dump(mode="json")
    return model.dict()  # pragma: no cover - v1 fallback


__all__ = [
    "DocumentConvertedEvent",
    "PipelineFailedEvent",
    "ValidationError",
    "model_to_dict",
    "parse_document_converted_event",
    "utc_now",
    "validate_document_converted_event",
]

