"""UTC refresh boundaries, matching the supported legacy cadence defaults."""

from datetime import datetime, timedelta, timezone


def next_refresh(cadence: str | None, now: datetime) -> datetime | None:
    if cadence is None:
        return None
    now = now.astimezone(timezone.utc)
    candidate = now.replace(hour=2, minute=0, second=0, microsecond=0)
    if cadence == "daily":
        return candidate if candidate > now else candidate + timedelta(days=1)
    if cadence == "weekly":
        candidate += timedelta(days=(6 - now.weekday()) % 7)
        return candidate if candidate > now else candidate + timedelta(days=7)
    if cadence == "monthly":
        candidate = candidate.replace(day=1)
        if candidate <= now:
            candidate = candidate.replace(
                year=now.year + (now.month == 12), month=now.month % 12 + 1
            )
        return candidate
    raise ValueError("Unsupported refresh cadence")
