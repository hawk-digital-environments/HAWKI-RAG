"""Transport-neutral failures raised by indexing application logic."""


class IndexerError(RuntimeError):
    """Base error for indexer-owned failures."""


class IndexingValidationError(IndexerError, ValueError):
    """The requested indexing operation is invalid."""


class EmbeddingError(IndexerError):
    """No prepared chunk could be embedded."""


__all__ = ["EmbeddingError", "IndexerError", "IndexingValidationError"]
