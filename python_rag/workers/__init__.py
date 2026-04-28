from .failure_classifier import (
    FAILURE_PERMANENT,
    FAILURE_TRANSIENT,
    PermanentIngestionError,
    TransientIngestionError,
    classify_failure,
)

__all__ = [
    "FAILURE_PERMANENT",
    "FAILURE_TRANSIENT",
    "PermanentIngestionError",
    "TransientIngestionError",
    "classify_failure",
]

