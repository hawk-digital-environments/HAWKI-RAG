"""Preserve URL spelling independently from security validation."""

import hashlib
import json
import re


def digest(value: object) -> str:
    return hashlib.sha256(
        json.dumps(value, sort_keys=True, separators=(",", ":")).encode()
    ).hexdigest()


def normalize_url(value: str) -> str:
    value = value.strip()
    if not re.match(r"^[a-z][a-z0-9+.-]*://", value, re.I):
        value = "https://" + value.lstrip("/")
    return value


def source_identity(tenant: str, dataset: str, url: str) -> str:
    # Tenant is part of the Python namespace. Never adopt a legacy source merely
    # because its dataset/URL hash happens to agree.
    return "source_" + digest([tenant, dataset, url])[:32]
