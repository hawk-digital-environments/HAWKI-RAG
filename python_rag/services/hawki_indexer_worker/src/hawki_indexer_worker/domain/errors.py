"""Transport-neutral failures raised by indexing application logic."""


class IndexerError(RuntimeError):
    """Base error for indexer-owned failures."""


class IndexingValidationError(IndexerError, ValueError):
    """The requested indexing operation is invalid."""


class EmbeddingError(IndexerError):
    """One or more required chunks could not be embedded."""


class DocumentCompletionError(IndexerError):
    """A complete document revision could not be durably proven."""


__all__ = [
    "DocumentCompletionError",
    "EmbeddingError",
    "IndexerError",
    "IndexingValidationError",
]
