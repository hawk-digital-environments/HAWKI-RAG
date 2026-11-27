"""Server-owned settings for URL ingestion."""

import os
from dataclasses import dataclass
from pathlib import Path
from collections.abc import Mapping


@dataclass(frozen=True)
class UrlIngestionSettings:
    enabled: bool = False
    shared_root: str = "/shared"
    profile_root: str | None = None


def load_url_ingestion_settings(
    env: Mapping[str, str] | None = None,
) -> UrlIngestionSettings:
    env = os.environ if env is None else env
    root = env.get("RAG_TEMPORAL_SHARED_ROOT", "/shared")
    if (
        not Path(root).is_absolute()
        or Path(root) == Path("/")
        or ".." in Path(root).parts
    ):
        raise ValueError("URL ingestion requires an absolute shared artifact root")
    return UrlIngestionSettings(
        enabled=env.get("RAG_BACKEND_URL_INGESTION_ENABLED", "false").lower()
        in {"1", "true", "yes"},
        shared_root=root,
        profile_root=env.get("RAG_URL_SITE_PROFILE_ROOT") or None,
    )
