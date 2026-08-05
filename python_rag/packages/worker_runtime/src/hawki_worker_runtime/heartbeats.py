"""Injectable activity heartbeat helper."""

from __future__ import annotations

from collections.abc import Callable


def heartbeat(
    details: object, *, sender: Callable[[object], None] | None = None
) -> None:
    """Emit heartbeat details when the owning activity supplies a sender."""

    if sender is not None:
        sender(details)


__all__ = ["heartbeat"]
