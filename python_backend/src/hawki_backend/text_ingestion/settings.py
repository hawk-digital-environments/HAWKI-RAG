"""Server-owned configuration for the Python direct-text ingestion boundary.

Only ``HAWKI_BACKEND_TEXT_INGESTION_*`` is read here. The public size limits
are constants because they are part of the external contract, not deployment
tuning: the Laravel compatibility BFF and this service must refuse exactly the
same bodies.
"""

from __future__ import annotations

import os
import re
from collections.abc import Mapping
from dataclasses import dataclass, field
from urllib.parse import urlsplit

_PREFIX = "HAWKI_BACKEND_TEXT_INGESTION_"

_KNOWN_SUFFIXES = frozenset(
    {
        "ENABLED",
        "SHARED_ROOT",
        "QDRANT_URL",
        "QDRANT_API_KEY",
    }
)

# External contract, inclusive on both boundaries. They mirror
# ``config/config.php`` exactly and are deliberately not configurable.
MAX_REQUEST_BYTES = 4_300_000
MAX_TEXT_CHARACTERS = 1_048_576
MAX_METADATA_BYTES = 65_536
FINGERPRINT_VERSION = 2

_DEFAULT_SHARED_ROOT = "/shared"
_DEFAULT_QDRANT_URL = "http://qdrant:6333"
_TRUE = frozenset({"1", "true", "yes", "on"})
_FALSE = frozenset({"0", "false", "no", "off"})


class TextIngestionConfigurationError(Exception):
    """Raised when the text-ingestion configuration is present but invalid."""


@dataclass(frozen=True, slots=True)
class TextIngestionSettings:
    """Validated server-owned settings of the direct-text boundary."""

    enabled: bool = False
    shared_root: str = _DEFAULT_SHARED_ROOT
    qdrant_url: str = _DEFAULT_QDRANT_URL
    qdrant_api_key: str = field(default="", repr=False)

    def __post_init__(self) -> None:
        """Reject a shared root or Qdrant URL that could escape its scope."""
        root = self.shared_root.rstrip("/")
        if (
            not root
            or not root.startswith("/")
            or re.search(r"(^|/)\.{1,2}(/|$)", root) is not None
        ):
            raise TextIngestionConfigurationError(
                "the shared storage root must be a canonical absolute directory"
            )
        object.__setattr__(self, "shared_root", root)
        parts = urlsplit(self.qdrant_url)
        if parts.scheme not in {"http", "https"} or not parts.netloc:
            raise TextIngestionConfigurationError(
                "the Qdrant URL must be an http(s) URL with a host"
            )


def load_text_ingestion_settings(
    environ: Mapping[str, str] | None = None,
) -> TextIngestionSettings:
    """Load the text-ingestion settings; an unset group means disabled.

    Args:
        environ: The environment to read. Defaults to ``os.environ``.

    Returns:
        The validated settings. An absent group yields ``enabled=False`` with
        the default shared root, which fails closed at the route boundary.

    Raises:
        TextIngestionConfigurationError: When a configured value is invalid.
    """
    source = os.environ if environ is None else environ
    present = {name for name in source if name.startswith(_PREFIX)}
    _reject_unknown_names(present)
    return TextIngestionSettings(
        enabled=_boolean(source, "ENABLED", False),
        shared_root=_text(source, "SHARED_ROOT", _DEFAULT_SHARED_ROOT),
        qdrant_url=_text(source, "QDRANT_URL", _DEFAULT_QDRANT_URL),
        qdrant_api_key=_optional_secret(source, "QDRANT_API_KEY"),
    )


def _env_name(suffix: str) -> str:
    return f"{_PREFIX}{suffix}"


def _reject_unknown_names(present: set[str]) -> None:
    known = {_env_name(suffix) for suffix in _KNOWN_SUFFIXES}
    unknown = sorted(present - known)
    if unknown:
        raise TextIngestionConfigurationError(
            "unknown text-ingestion configuration: " + ", ".join(unknown)
        )


def _text(source: Mapping[str, str], suffix: str, default: str) -> str:
    raw = source.get(_env_name(suffix))
    if raw is None:
        return default
    value = raw.strip()
    if not value:
        raise TextIngestionConfigurationError(f"{_env_name(suffix)} must not be empty")
    return value


def _optional_secret(source: Mapping[str, str], suffix: str) -> str:
    raw = source.get(_env_name(suffix))
    return "" if raw is None else raw


def _boolean(source: Mapping[str, str], suffix: str, default: bool) -> bool:
    raw = source.get(_env_name(suffix))
    if raw is None:
        return default
    value = raw.strip().lower()
    if value in _TRUE:
        return True
    if value in _FALSE:
        return False
    raise TextIngestionConfigurationError(f"{_env_name(suffix)} must be a boolean")


__all__ = [
    "FINGERPRINT_VERSION",
    "MAX_METADATA_BYTES",
    "MAX_REQUEST_BYTES",
    "MAX_TEXT_CHARACTERS",
    "TextIngestionConfigurationError",
    "TextIngestionSettings",
    "load_text_ingestion_settings",
]
