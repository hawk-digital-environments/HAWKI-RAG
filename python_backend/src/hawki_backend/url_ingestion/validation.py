"""Central public-network policy; validation alone never authorizes a fetch.

The external crawler currently has no verified IP pinning/redirect contract.
Consequently the production boundary refuses dispatch even for a public URL.
Do not replace this refusal with an environment flag or a single DNS lookup.
"""

import ipaddress
import re
import socket
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlsplit

from pydantic import AliasChoices, BaseModel, ConfigDict, Field, model_validator

from .errors import UrlIngestionError
from .identity import normalize_url


def resolve_addresses(host: str, port: int) -> tuple[str, ...]:
    return tuple(
        sorted(
            {
                row[4][0]
                for row in socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
                if row[0] in {socket.AF_INET, socket.AF_INET6}
            }
        )
    )


@dataclass(frozen=True)
class ValidatedUrl:
    identity: str
    host: str
    port: int
    addresses: tuple[str, ...]


class UrlSafetyValidator:
    def __init__(
        self, resolver: Callable[[str, int], tuple[str, ...]] = resolve_addresses
    ) -> None:
        self.resolver = resolver

    def validate(self, raw: str) -> ValidatedUrl:
        if (
            not raw.strip()
            or len(raw) > 4096
            or "\\" in raw
            or any(ord(char) < 33 or ord(char) == 127 for char in raw.strip())
        ):
            raise UrlIngestionError(422, "Unsafe URL.")
        # Reject opaque schemes before legacy normalization could disguise them
        # as an HTTPS host. Host:port is accepted only with an explicit scheme.
        scheme = re.match(r"^([a-z][a-z0-9+.-]*):", raw.strip(), re.I)
        if scheme and scheme[1].lower() not in {"http", "https"}:
            raise UrlIngestionError(422, "Only HTTP and HTTPS URLs are supported.")
        identity = normalize_url(raw)
        try:
            parsed = urlsplit(identity)
            if (
                parsed.scheme.lower() not in {"http", "https"}
                or not parsed.hostname
                or parsed.username is not None
                or parsed.password is not None
                or "%" in parsed.hostname
            ):
                raise ValueError()
            host = parsed.hostname.encode("idna").decode("ascii").lower().rstrip(".")
            port = parsed.port or (443 if parsed.scheme.lower() == "https" else 80)
            if (
                host == "localhost"
                or host.endswith((".localhost", ".local", ".internal"))
                or host in {"metadata", "metadata.google.internal"}
            ):
                raise ValueError()
            addresses = self.resolver(host, port)
            if not addresses:
                raise ValueError()
            for address in addresses:
                ip = ipaddress.ip_address(address)
                # Reject mapped/transition forms too: the crawler's network
                # interpretation must not differ from the policy's.
                if (
                    not ip.is_global
                    or ip.is_multicast
                    or str(ip) == "168.63.129.16"
                    or (
                        isinstance(ip, ipaddress.IPv6Address)
                        and (ip.ipv4_mapped or ip.sixtofour or ip.teredo)
                    )
                ):
                    raise ValueError()
        except (ValueError, UnicodeError, OSError):
            raise UrlIngestionError(
                422, "URL must resolve only to public addresses."
            ) from None
        return ValidatedUrl(identity, host, port, tuple(sorted(set(addresses))))


class ExternalCrawlerBoundary:
    """Fail closed until the crawler can enforce safety for every network hop."""

    def require_safe_fetch(self, urls: list[ValidatedUrl]) -> None:
        raise UrlIngestionError(
            503,
            "URL ingestion is blocked: the crawler cannot prove pinned DNS and redirect safety.",
        )


def site_profile(value: object, root: str | None) -> str | None:
    if value is None:
        return None
    if not isinstance(value, str) or not value or not root:
        raise UrlIngestionError(422, "Select a server-owned site profile.")
    try:
        allowed = Path(root).resolve(strict=True)
        supplied = Path(value)
        if ".." in supplied.parts:
            raise ValueError()
        candidate = (
            supplied if supplied.is_absolute() else allowed / supplied
        ).resolve(strict=True)
        if not candidate.is_relative_to(allowed) or not candidate.is_file():
            raise ValueError()
    except (ValueError, OSError, RuntimeError):
        raise UrlIngestionError(422, "Select a server-owned site profile.") from None
    return str(candidate)


class UrlStartInput(BaseModel):
    model_config = ConfigDict(extra="forbid", populate_by_name=True)

    task_id: str | None = Field(
        default=None, max_length=191, validation_alias=AliasChoices("task_id", "taskId")
    )
    dataset_id: str = Field(
        min_length=1,
        max_length=191,
        validation_alias=AliasChoices("dataset_id", "datasetId"),
    )
    source_url: str | None = Field(
        default=None,
        max_length=4096,
        validation_alias=AliasChoices("source_url", "sourceUrl"),
    )
    urls: str | list[str] | None = None
    sitemap_url: str | None = Field(
        default=None,
        max_length=4096,
        validation_alias=AliasChoices("sitemap_url", "sitemapUrl"),
    )
    sitemap_path: str | None = Field(
        default=None,
        max_length=4096,
        validation_alias=AliasChoices("sitemap_path", "sitemapPath"),
    )
    refresh_cadence: str | None = Field(
        default=None,
        validation_alias=AliasChoices("refresh_cadence", "refreshCadence", "cadence"),
    )
    metadata: dict = Field(default_factory=dict)

    @model_validator(mode="after")
    def validate_request(self) -> "UrlStartInput":
        cadence = self.refresh_cadence
        if cadence is None and "refresh_cadence" not in self.model_fields_set:
            cadence = next(
                (
                    self.metadata[key]
                    for key in ("refresh_cadence", "refreshCadence", "cadence")
                    if self.metadata.get(key) is not None
                ),
                None,
            )
        if isinstance(cadence, str) and cadence in {"", "none"}:
            cadence = None
        if cadence is not None and (
            not isinstance(cadence, str)
            or cadence not in {"daily", "weekly", "monthly"}
        ):
            raise ValueError("Invalid refresh cadence")
        self.refresh_cadence = cadence
        # Metadata round-trips, but may not redirect the worker's physical
        # service, credentials, writer identity or artifact destinations.
        forbidden = {
            "external_services",
            "sitemap_url",
            "sitemap_path",
            "sitemap_base",
            "sitemap",
            "scraper_url",
            "scraper_token",
            "output_dir",
            "raw_output_path",
            "markdown_output_path",
            "url_ingestion_operation_id",
            "managed_document_id",
            "owner",
            "workflow_id",
            "storage",
            "upload",
        }
        if forbidden.intersection(self.metadata):
            raise ValueError("Server-owned fields cannot be supplied in metadata")
        return self

    def requested_urls(self) -> list[str]:
        values = (
            re.split(r"[\r\n,]+", self.urls)
            if isinstance(self.urls, str)
            else self.urls
        )
        values = [value.strip() for value in (values or []) if value.strip()]
        if not values and self.source_url and self.source_url.strip():
            values = [self.source_url.strip()]
        if len(values) > 1000:
            raise UrlIngestionError(422, "At most 1000 URLs are allowed.")
        return list(dict.fromkeys(values))
