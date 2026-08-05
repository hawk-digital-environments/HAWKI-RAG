"""Bridge application errors translated at the HTTP boundary."""


class BridgeQueryError(RuntimeError):
    """A query could not be completed without violating its scope."""


__all__ = ["BridgeQueryError"]
