"""Connection settings for local API smoke tests and benchmarks."""

import os
from pathlib import Path
from urllib.parse import urlsplit


def api_url() -> str:
    url = os.getenv("RAWKI_API_BASE_URL", "").strip().rstrip("/")
    try:
        parts = urlsplit(url)
        valid = (
            parts.scheme in {"http", "https"}
            and parts.hostname
            and parts.port != 0
            and parts.path == "/v1"
            and not any(char.isspace() for char in url)
            and parts.username is None
            and parts.password is None
            and not (parts.query or parts.fragment)
        )
    except ValueError:
        valid = False
    if not valid:
        raise SystemExit("Set RAWKI_API_BASE_URL to an HTTP(S) API URL ending in /v1.")
    return url


def service_headers() -> dict[str, str]:
    filename = os.getenv("RAWKI_API_TOKEN_FILE")
    if not filename:
        raise SystemExit("Set RAWKI_API_TOKEN_FILE to a local service JWT file.")
    try:
        token = Path(filename).read_text(encoding="utf-8").strip()
    except (OSError, UnicodeError):
        raise SystemExit("Cannot read the local service JWT file.") from None
    if not token or not token.isascii() or any(char.isspace() for char in token):
        raise SystemExit("The local service JWT file does not contain a bearer token.")
    return {"Authorization": f"Bearer {token}"}
