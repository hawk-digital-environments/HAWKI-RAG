"""HTTP errors owned by the reranker boundary."""

from __future__ import annotations

from fastapi import HTTPException


class InvalidRerankRequest(HTTPException):
    """A syntactically valid request has unusable reranking content."""

    def __init__(self, detail: str = "query and documents are required") -> None:
        super().__init__(status_code=400, detail=detail)


__all__ = ["InvalidRerankRequest"]
