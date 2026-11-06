"""One canonical scraper setting, composed once at startup."""

import os
from dataclasses import dataclass, field
from pathlib import Path
from urllib.parse import urlsplit
from collections.abc import Mapping


@dataclass(frozen=True)
class UrlIngestionSettings:
    enabled: bool = False
    scraper_url: str = ""
    shared_root: str = "/shared"
    profile_root: str | None = None
    scraper_token: str = field(default="", repr=False)


def load_url_ingestion_settings(
    env: Mapping[str, str] | None = None,
) -> UrlIngestionSettings:
    env = os.environ if env is None else env
    scraper = env.get("EXTERNAL_SCRAPER_URL", "").strip()
    if not scraper:
        scraper = env.get("CUSTOM_CRAWLER_URL", "").strip()
    if scraper:
        parts = urlsplit(scraper)
        if (
            parts.scheme not in {"http", "https"}
            or not parts.hostname
            or parts.username is not None
            or parts.password is not None
            or parts.query
            or parts.fragment
        ):
            raise ValueError("EXTERNAL_SCRAPER_URL must be a server-owned HTTP URL")
    root = env.get("HAWKI_RAG_TEMPORAL_SHARED_ROOT", "/shared")
    if (
        not Path(root).is_absolute()
        or Path(root) == Path("/")
        or ".." in Path(root).parts
    ):
        raise ValueError("URL ingestion requires an absolute shared artifact root")
    return UrlIngestionSettings(
        enabled=env.get("HAWKI_BACKEND_URL_INGESTION_ENABLED", "false").lower()
        in {"1", "true", "yes"},
        scraper_url=scraper.rstrip("/"),
        shared_root=root,
        profile_root=env.get("HAWKI_URL_SITE_PROFILE_ROOT") or None,
        scraper_token=env.get(
            "EXTERNAL_SCRAPER_TOKEN", env.get("CUSTOM_CRAWLER_API_KEY", "")
        ),
    )
