"""Bridge application errors translated at the HTTP boundary."""


class BridgeQueryError(RuntimeError):
    """A query could not be completed without violating its scope."""


class DatasetVectorStoreNotReadyError(BridgeQueryError):
    """The authorized dataset has no ready vector collection."""


__all__ = ["BridgeQueryError", "DatasetVectorStoreNotReadyError"]
