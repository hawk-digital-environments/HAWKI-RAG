"""Bridge application errors translated at the HTTP boundary."""


class BridgeQueryError(RuntimeError):
    """A query could not be completed without violating its scope."""


class DatasetVectorStoreNotReadyError(BridgeQueryError):
    """The authorized dataset has no ready vector collection."""


class InvalidQueryError(BridgeQueryError):
    """The query cannot safely or meaningfully enter retrieval."""


class UnsupportedModelProviderError(InvalidQueryError):
    """The request names a model provider unavailable to this bridge."""


class EmbeddingGenerationError(BridgeQueryError):
    """The selected provider could not embed the authorized query."""


class AnswerGenerationError(BridgeQueryError):
    """The selected provider could not generate the grounded answer."""


__all__ = [
    "AnswerGenerationError",
    "BridgeQueryError",
    "DatasetVectorStoreNotReadyError",
    "EmbeddingGenerationError",
    "InvalidQueryError",
    "UnsupportedModelProviderError",
]
