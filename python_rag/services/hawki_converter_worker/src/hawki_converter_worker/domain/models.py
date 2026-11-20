"""Typed values shared by converter application and adapters."""

from __future__ import annotations

from dataclasses import dataclass, replace

from hawki_rag_contracts.pipeline.ingestion import IngestionStatus


@dataclass(frozen=True, slots=True)
class ConverterEndpointConfig:
    """Validated connection and retry settings for one converter endpoint."""

    base_url: str
    start_path: str
    status_path: str
    token: str
    timeout_seconds: float
    retry_attempts: int
    poll_interval_seconds: float
    poll_timeout_seconds: float

    @property
    def uses_direct_extract(self) -> bool:
        """Return whether this endpoint exposes the synchronous `/extract` API."""

        return self.start_path.strip().strip("/") == "extract"

    def with_start_path(self, start_path: str) -> "ConverterEndpointConfig":
        """Return a copy routed to a different endpoint path."""

        return replace(self, start_path=start_path)

    def external_job_options(self) -> dict[str, object]:
        """Return constructor options for the asynchronous converter client."""

        return {
            "base_url": self.base_url,
            "start_path": self.start_path,
            "status_path": self.status_path,
            "token": self.token,
            "timeout_seconds": self.timeout_seconds,
            "retry_attempts": self.retry_attempts,
            "poll_interval_seconds": self.poll_interval_seconds,
            "poll_timeout_seconds": self.poll_timeout_seconds,
        }


@dataclass(frozen=True, slots=True)
class ConversionRunResult:
    """Storage-neutral outcome produced by direct or asynchronous conversion."""

    source_id: str
    markdown_dir: str
    status: IngestionStatus
    external_job_id: str | None = None
    markdown_files_created: int = 0
    error_details: str | None = None
    converted_files: tuple[str, ...] = ()
    passthrough_files: tuple[str, ...] = ()


__all__ = ["ConversionRunResult", "ConverterEndpointConfig"]
