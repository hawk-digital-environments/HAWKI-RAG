"""Translate bridge query errors into stable HTTP exceptions."""

from fastapi import HTTPException

from hawki_bridge.domain.errors import (
    AnswerGenerationError,
    BridgeQueryError,
    DatasetVectorStoreNotReadyError,
    EmbeddingGenerationError,
    InvalidQueryError,
    UnsupportedModelProviderError,
)


def query_error_to_http_exception(exc: BridgeQueryError) -> HTTPException:
    """Map application failures without leaking FastAPI into query execution."""

    if isinstance(exc, DatasetVectorStoreNotReadyError):
        return HTTPException(
            status_code=503,
            detail={
                "code": "dataset_not_ready",
                "message": "The authorized dataset storage is not ready.",
            },
        )
    if isinstance(exc, (InvalidQueryError, UnsupportedModelProviderError)):
        return HTTPException(status_code=400, detail=str(exc))
    if isinstance(exc, EmbeddingGenerationError):
        return HTTPException(status_code=500, detail=str(exc))
    if isinstance(exc, AnswerGenerationError):
        return HTTPException(status_code=502, detail="Answer generation failed.")
    return HTTPException(status_code=500, detail="Query execution failed.")


__all__ = ["query_error_to_http_exception"]
