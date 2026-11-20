"""CustomCrawler's execution contract, shared by admission, polling and retirement.

This validates compatibility and receipts, not network security. The reviewed
crawler owns connection-bound network enforcement and fails browser startup
before launching Chromium if Linux isolation cannot be established.
"""

from pathlib import PurePosixPath
from typing import Any

from .crawler_constraints import require_domain, require_values

from pydantic import (
    AwareDatetime,
    BaseModel,
    ConfigDict,
    Field,
    StrictBool,
    model_validator,
)

CRAWLER_CONTRACT = "permanent-job-v2"

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
        if (
            any(
                not isinstance(wire.get(key), str) or not wire[key].strip()
                for key in (
                    "dataset_id",
                    "task_id",
                    "source_id",
                    "source_url",
                    "url_ingestion_operation_id",
                )
            )
            or type(wire.get("generation")) is not int
            or wire["generation"] < 1
        ):
            raise ValueError("Crawler ownership identity is incomplete")
        if self.job_id != wire.get("job_id") or any(
            self.metadata.get(key) != wire.get(key)
            for key in (
                "source_id",
                "dataset_id",
                "task_id",
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
    """Require external v2 advertisement and typed, required ownership receipts."""
    try:
        if document.get("x-hawki-crawler-contract") != CRAWLER_CONTRACT:
            raise ValueError("Crawler does not advertise permanent-job-v2")

        def resolve(schema):
            seen = set()
            while "$ref" in schema:
                if set(schema) - {"$ref", "title", "description"}:
                    raise ValueError(
                        "Crawler reference has unreviewed sibling constraints"
                    )
                ref = schema["$ref"]
                if not ref.startswith("#/components/schemas/") or ref in seen:
                    raise ValueError("Unsupported crawler schema reference")
                seen.add(ref)
                schema = document["components"]["schemas"][ref.rsplit("/", 1)[-1]]
            return schema

        def fields(schema, types):
            schema = resolve(schema)
            if schema.get("type") != "object" or set(schema) - {
                "type",
                "properties",
                "required",
                "additionalProperties",
                "title",
                "description",
            }:
                raise ValueError("Unsupported crawler object predicate")
            if not set(types) <= set(schema.get("required", [])):
                raise ValueError("Crawler required fields missing")
            for name, kind in types.items():
                prop = resolve(schema["properties"][name])
                if prop.get("type") != kind:
                    raise ValueError("Crawler identity type incompatible")
            return schema["properties"]

        identity = dict.fromkeys(
            (
                "dataset_id",
                "task_id",
                "source_id",
                "source_url",
                "url_ingestion_operation_id",
            ),
            "string",
        )
        identity["generation"] = "integer"
        paths = document["paths"]
        request = paths["/crawl"]["post"]["requestBody"]["content"]["application/json"][
            "schema"
        ]
        props = fields(
            request,
            {**identity, "job_id": "string", "url": "string", "output_dir": "string"},
        )
        for name, kind in {
            **identity,
            "job_id": "string",
            "url": "string",
            "output_dir": "string",
        }.items():
            require_domain(resolve(props[name]), name, kind)
        if (
            not props["job_id"].get("pattern")
            or props["generation"].get("minimum", 0) < 1
        ):
            raise ValueError("Crawler permanent identity constraints missing")
        if not {"sitemap", "sitemap_base", "sitemap_only"} <= set(props):
            raise ValueError("Crawler sitemap contract missing")
        for name, kind in {
            "sitemap": "boolean",
            "sitemap_only": "boolean",
            "sitemap_base": "string",
        }.items():
            require_domain(resolve(props[name]), name, kind)
        request_schema = resolve(request)
        if type(request_schema.get("additionalProperties", True)) is not bool:
            raise ValueError("Unreviewed crawler additional-property predicate")
        forwarded_options = {
            "site_profile_path",
            "rescrape_failed",
            "max_pages",
            "max_concurrency",
            "max_rpm",
            "skip_images",
            "max_images_per_page",
            "max_link_density",
            "discovery_mode",
            "wait_until",
            "page_timeout_ms",
        }
        if request_schema.get(
            "additionalProperties"
        ) is False and not forwarded_options <= set(props):
            raise ValueError("Crawler forbids RAWKI optional request fields")
        if (
            set(request_schema.get("required", []))
            - {
                **identity,
                "job_id": "string",
                "url": "string",
                "output_dir": "string",
            }.keys()
        ):
            raise ValueError(
                "Crawler requires fields omitted by ordinary RAWKI admission"
            )
        if set(request_schema) - {
            "type",
            "properties",
            "required",
            "additionalProperties",
            "title",
            "description",
        }:
            raise ValueError("Unreviewed crawler request body predicate")
        admission = paths["/crawl"]["post"]["responses"]["200"]["content"][
            "application/json"
        ]["schema"]
        admission_fields = fields(
            admission, {"job_id": "string", "event": "string", "data": "object"}
        )
        admission_data = fields(admission_fields["data"], {"sealed": "boolean"})
        require_values(resolve(admission_data["sealed"]), (False,))
        require_domain(resolve(admission_fields["job_id"]), "job_id", "string")
        if set(admission_fields["event"].get("enum", [])) != {
            "job_submitted",
            "job_exists",
        }:
            raise ValueError("Crawler admission semantics missing")
        require_values(
            resolve(admission_fields["event"]), ("job_submitted", "job_exists")
        )
        status = paths["/status/{job_id}"]["get"]
        receipt = status["responses"]["200"]["content"]["application/json"]["schema"]
        props = fields(
            receipt,
            {
                "job_id": "string",
                "external_job_id": "string",
                "status": "string",
                "state": "string",
                "terminal": "boolean",
                "sealed": "boolean",
                "quiescent": "boolean",
                "writer_admission_closed": "boolean",
                "metadata": "object",
                "output_directory": "string",
                "pages_crawled": "integer",
            },
        )
        if "sealed_at" not in resolve(receipt).get("required", []) or resolve(
            props["sealed_at"]
        ) != {"anyOf": [{"type": "string", "format": "date-time"}, {"type": "null"}]}:
            raise ValueError("Crawler seal timestamp contract missing")
        fields(props["metadata"], identity)
        for name in ("job_id", "external_job_id", "output_directory"):
            require_domain(resolve(props[name]), name, "string")
        for name in ("terminal", "sealed", "quiescent", "writer_admission_closed"):
            require_values(resolve(props[name]), (False, True))
        require_domain(resolve(props["pages_crawled"]), "pages_crawled", "integer")
        for name in ("status", "state"):
            require_values(resolve(props[name]), STATES)
        for name, kind in identity.items():
            require_domain(
                resolve(resolve(props["metadata"])["properties"][name]), name, kind
            )
        if (
            set(props["status"].get("enum", [])) != STATES
            or set(props["state"].get("enum", [])) != STATES
        ):
            raise ValueError("Crawler lifecycle contract incompatible")
        all_parameters = [
            *paths["/status/{job_id}"].get("parameters", []),
            *status.get("parameters", []),
        ]
        if any(
            p.get("required") and p.get("name") != "job_id" or "$ref" in p
            for p in all_parameters
        ):
            raise ValueError("Crawler status requires an unsupported parameter")
        parameters = [p for p in all_parameters if p.get("name") == "job_id"]
        if (
            len(parameters) != 1
            or parameters[0].get("in") != "path"
            or parameters[0].get("required") is not True
        ):
            raise ValueError("Crawler patterned status route missing")
        require_domain(resolve(parameters[0]["schema"]), "job_id", "string")
        if not resolve(parameters[0]["schema"]).get("pattern"):
            raise ValueError("Crawler patterned status route missing")
        cancel = paths["/jobs/{job_id}/cancel"]
        cancel_parameters = [
            *cancel.get("parameters", []),
            *cancel["post"].get("parameters", []),
        ]
        for parameter in cancel_parameters:
            if parameter.get("name") == "job_id":
                require_domain(resolve(parameter["schema"]), "job_id", "string")
            elif parameter.get("required") or "$ref" in parameter:
                raise ValueError(
                    "Crawler cancellation requires an unsupported parameter"
                )
    except (KeyError, TypeError, AttributeError) as error:
        raise ValueError("Incompatible CustomCrawler API") from error
