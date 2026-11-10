"""CustomCrawler's execution contract, shared by admission, polling and retirement.

This validates compatibility and receipts, not network security. The reviewed
crawler owns connection-bound network enforcement and fails browser startup
before launching Chromium if Linux isolation cannot be established.
"""

from pathlib import PurePosixPath
from typing import Any

from pydantic import (
    AwareDatetime,
    BaseModel,
    ConfigDict,
    Field,
    StrictBool,
    model_validator,
)

TERMINAL = frozenset({"completed", "failed", "cancelled"})
STATES = TERMINAL | {"pending", "running", "paused", "cancelling", "unknown"}


class CrawlerReceipt(BaseModel):
    model_config = ConfigDict(extra="ignore", frozen=True)

    job_id: str = Field(pattern=r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,190}$")
    external_job_id: str
    status: str
    state: str
    terminal: StrictBool
    sealed: StrictBool
    sealed_at: AwareDatetime | None
    quiescent: StrictBool
    writer_admission_closed: StrictBool
    output_directory: str
    metadata: dict[str, Any]
    pages_crawled: int = Field(default=0, ge=0)

    @model_validator(mode="after")
    def consistent(self):
        if (
            self.job_id != self.external_job_id
            or self.status not in STATES
            or self.state != self.status
            or self.terminal != (self.status in TERMINAL)
        ):
            raise ValueError("Inconsistent crawler lifecycle identity")
        if self.sealed and not (
            self.terminal
            and self.quiescent
            and self.writer_admission_closed
            and self.sealed_at
        ):
            raise ValueError("Sealed crawler receipt lacks drainage evidence")
        return self

    @property
    def drained(self) -> bool:
        return (
            self.terminal
            and self.sealed
            and self.quiescent
            and self.writer_admission_closed
            and self.sealed_at is not None
        )

    def verify_owner(self, wire: dict) -> None:
        if self.job_id != wire.get("job_id") or any(
            self.metadata.get(key) != wire.get(key)
            for key in (
                "source_id",
                "source_url",
                "generation",
                "url_ingestion_operation_id",
            )
        ):
            raise ValueError("Crawler receipt owner differs from its URL generation")
        path = worker_path(self.output_directory, wire["storage"]["shared_root"])
        if path != PurePosixPath(wire["raw_output_path"]) / self.job_id:
            raise ValueError("Crawler artifact root differs from its reserved job")


def worker_path(value: str, shared_root: str) -> PurePosixPath:
    path = PurePosixPath(value)
    if not path.is_absolute() or ".." in path.parts:
        raise ValueError("Invalid crawler artifact path")
    for prefix in ("/var/www/html/shared", "/app/shared", "/shared"):
        if path.is_relative_to(prefix):
            return PurePosixPath(shared_root) / path.relative_to(prefix)
    return path


def require_crawler_api(document: dict) -> None:
    """Reject legacy/unrelated endpoints before any customer job is dispatched.

    OpenAPI proves interface compatibility only. Deployment must use the reviewed
    CustomCrawler implementation; its protected browser performs its own live
    namespace/seccomp checks. /health alone proves neither fact.
    """
    try:
        paths = document["paths"]
        reference = paths["/crawl"]["post"]["requestBody"]["content"][
            "application/json"
        ]["schema"]["$ref"]
        schema = document["components"]["schemas"][reference.rsplit("/", 1)[-1]]
        properties = schema["properties"]
        required = {
            "job_id",
            "source_id",
            "source_url",
            "generation",
            "url_ingestion_operation_id",
            "url",
            "output_dir",
            "sitemap",
            "sitemap_base",
            "sitemap_only",
        }
        if not required.issubset(properties) or not properties["job_id"].get("pattern"):
            raise ValueError("Missing permanent-job API")
        status = paths["/status/{job_id}"]["get"]
        if status["responses"]["200"]["content"]["application/json"]["schema"].get(
            "$ref"
        ):
            raise ValueError("Legacy status model strips sealed receipts")
        if not any(
            parameter.get("name") == "job_id"
            and parameter.get("schema", {}).get("pattern")
            for parameter in status["parameters"]
        ):
            raise ValueError("Missing permanent-job status API")
        paths["/jobs/{job_id}/cancel"]["post"]
    except (KeyError, TypeError, AttributeError) as error:
        raise ValueError("Incompatible CustomCrawler API") from error
