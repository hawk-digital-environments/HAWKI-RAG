"""Tests for the stable public query error mapping."""

from __future__ import annotations

import pytest

from hawki_backend.query.errors import (
    authorization_unavailable,
    dataset_not_found,
    dataset_not_ready,
    query_failed,
    query_unavailable,
    retrieval_error_to_http_exception,
)
from hawki_rag_retrieval.errors import (
    AnswerGenerationError,
    BridgeQueryError,
    DatasetVectorStoreNotReadyError,
    EmbeddingGenerationError,
    InvalidQueryError,
    UnsupportedModelProviderError,
)


def test_denial_and_authorization_infrastructure_are_distinct() -> None:
    denial = dataset_not_found()
    unavailable = authorization_unavailable()

    assert denial.status_code == 404
    assert unavailable.status_code == 503
    assert denial.detail["error"] == "dataset_not_found"
    assert unavailable.detail["error"] == "authorization_unavailable"
    assert denial.detail["error"] != unavailable.detail["error"]


@pytest.mark.parametrize(
    ("error", "status", "code"),
    [
        (DatasetVectorStoreNotReadyError(), 409, "dataset_not_ready"),
        (InvalidQueryError(), 400, "invalid_query"),
        (UnsupportedModelProviderError(), 400, "invalid_query"),
        (EmbeddingGenerationError(), 500, "embedding_failed"),
        (AnswerGenerationError(), 502, "answer_generation_failed"),
        (BridgeQueryError(), 500, "query_failed"),
    ],
)
def test_retrieval_errors_map_to_stable_public_errors(
    error: BridgeQueryError,
    status: int,
    code: str,
) -> None:
    response = retrieval_error_to_http_exception(error)

    assert response.status_code == status
    assert response.detail["error"] == code


def test_scope_and_configuration_errors_are_stable() -> None:
    assert dataset_not_ready().status_code == 409
    assert dataset_not_ready().detail["error"] == "dataset_not_ready"
    assert query_unavailable().status_code == 503
    assert query_unavailable().detail["error"] == "query_unavailable"
    assert query_failed().status_code == 500
    assert query_failed().detail["error"] == "query_failed"


def test_error_messages_do_not_carry_exception_detail() -> None:
    error = InvalidQueryError("provider leaked a secret")
    response = retrieval_error_to_http_exception(error)

    assert "secret" not in response.detail["message"]
    assert "provider leaked" not in response.detail["message"]
