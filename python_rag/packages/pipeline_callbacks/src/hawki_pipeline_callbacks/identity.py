"""Deterministic identities for retry-safe worker callback events."""

from __future__ import annotations

import hashlib


def deterministic_event_id(
    *,
    workflow_id: str,
    run_id: str,
    activity_id: str,
    attempt: int,
    status: str,
    prefix: str,
) -> str:
    """Return the stable callback id for one activity attempt and status."""

    identity = "|".join((workflow_id, run_id, activity_id, str(int(attempt)), status))
    digest = hashlib.sha256(identity.encode("utf-8")).hexdigest()
    return f"{prefix}{digest}"


__all__ = ["deterministic_event_id"]
