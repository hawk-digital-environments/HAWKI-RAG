"""Stable public error mapping for the query boundary.

The bodies are deliberately generic. They never carry a database error, a
provider message, a collection name, a namespace name, a tenant or a grant.
A denial is distinct from an authorization infrastructure failure, and both
are distinct from a missing or incomplete dataset scope.
"""

from __future__ import annotations

from fastapi import HTTPException

from hawki_rag_retrieval.errors import (
    AnswerGenerationError,
    BridgeQueryError,
    DatasetVectorStoreNotReadyError,
    EmbeddingGenerationError,
    InvalidQueryError,
)

_DATASET_NOT_FOUND = "The requested dataset is not available."
_AUTHORIZATION_UNAVAILABLE = "Authorization could not be completed."
_DATASET_NOT_READY = "The requested dataset is not ready for querying."
_QUERY_UNAVAILABLE = "Query execution is not available."
_INVALID_QUERY = "The query could not be processed."
_EMBEDDING_FAILED = "Embedding generation failed."
_ANSWER_FAILED = "Answer generation failed."
_QUERY_FAILED = "Query execution failed."


def _error(status_code: int, error: str, message: str) -> HTTPException:
    """Return a stable, non-leaking HTTP error."""

    return HTTPException(
        status_code=status_code,
        detail={"error": error, "message": message},
    )


def dataset_not_found() -> HTTPException:
    """Return the denial response shared by every authorization refusal."""

    return _error(404, "dataset_not_found", _DATASET_NOT_FOUND)


def authorization_unavailable() -> HTTPException:
    """Return the response for a grant store that could not be consulted."""

    return _error(503, "authorization_unavailable", _AUTHORIZATION_UNAVAILABLE)


def dataset_not_ready() -> HTTPException:
    """Return the response for an authorized dataset without valid scope."""

    return _error(409, "dataset_not_ready", _DATASET_NOT_READY)


def query_unavailable() -> HTTPException:
    """Return the response when the query service is not configured."""

    return _error(503, "query_unavailable", _QUERY_UNAVAILABLE)


def query_failed() -> HTTPException:
    """Return the response for an unexpected query execution failure."""

    return _error(500, "query_failed", _QUERY_FAILED)


def retrieval_error_to_http_exception(exc: BridgeQueryError) -> HTTPException:
    """Translate a retrieval application error into a stable public error."""

    if isinstance(exc, DatasetVectorStoreNotReadyError):
        return dataset_not_ready()
    if isinstance(exc, InvalidQueryError):
        return _error(400, "invalid_query", _INVALID_QUERY)
    if isinstance(exc, EmbeddingGenerationError):
        return _error(500, "embedding_failed", _EMBEDDING_FAILED)
    if isinstance(exc, AnswerGenerationError):
        return _error(502, "answer_generation_failed", _ANSWER_FAILED)
    return _error(500, "query_failed", _QUERY_FAILED)


__all__ = [
    "authorization_unavailable",
    "dataset_not_found",
    "dataset_not_ready",
    "query_failed",
    "query_unavailable",
    "retrieval_error_to_http_exception",
]
