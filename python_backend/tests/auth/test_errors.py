"""Tests for the authentication failure taxonomy."""

import pytest

from hawki_backend.auth.errors import (
    AuthenticationError,
    AuthenticationUnavailableError,
    AuthFailureReason,
    InsufficientScopeError,
)


def test_failure_reasons_are_stable_strings() -> None:
    assert AuthFailureReason.MALFORMED_TOKEN == "malformed_token"
    assert AuthFailureReason.UNSUPPORTED_ALGORITHM == "unsupported_algorithm"
    assert AuthFailureReason.UNKNOWN_KEY == "unknown_key"
    assert AuthFailureReason.INVALID_SIGNATURE == "invalid_signature"
    assert AuthFailureReason.INVALID_ISSUER == "invalid_issuer"
    assert AuthFailureReason.INVALID_AUDIENCE == "invalid_audience"
    assert AuthFailureReason.MISSING_CLAIM == "missing_claim"
    assert AuthFailureReason.INVALID_CLAIM == "invalid_claim"
    assert AuthFailureReason.EXPIRED_TOKEN == "expired_token"
    assert AuthFailureReason.NOT_YET_VALID == "not_yet_valid"
    assert AuthFailureReason.ISSUED_IN_FUTURE == "issued_in_future"
    assert AuthFailureReason.LIFETIME_TOO_LONG == "lifetime_too_long"
    assert AuthFailureReason.CLIENT_NOT_ALLOWED == "client_not_allowed"
    assert AuthFailureReason.DELEGATION_NOT_SUPPORTED == "delegation_not_supported"
    assert AuthFailureReason.AUTHENTICATION_UNAVAILABLE == "authentication_unavailable"


def test_failure_reasons_are_unique() -> None:
    values = [reason.value for reason in AuthFailureReason]

    assert len(values) == len(set(values))


def test_authentication_error_carries_its_reason() -> None:
    error = AuthenticationError(AuthFailureReason.INVALID_SIGNATURE)

    assert error.reason is AuthFailureReason.INVALID_SIGNATURE
    assert str(error) == "invalid_signature"


def test_authentication_error_rejects_a_bare_string_reason() -> None:
    with pytest.raises(TypeError):
        AuthenticationError("invalid_signature")  # type: ignore[arg-type]


def test_authentication_unavailable_error_is_not_an_authentication_error() -> None:
    error = AuthenticationUnavailableError()

    assert not isinstance(error, AuthenticationError)
    assert error.reason is AuthFailureReason.AUTHENTICATION_UNAVAILABLE
    assert str(error) == "authentication_unavailable"


def test_the_three_failure_categories_are_siblings() -> None:
    categories = (
        AuthenticationError,
        AuthenticationUnavailableError,
        InsufficientScopeError,
    )

    for category in categories:
        assert issubclass(category, Exception)
        for other in categories:
            if other is not category:
                assert not issubclass(category, other)


def test_authentication_unavailable_error_is_distinct_from_a_refusal() -> None:
    unavailable = AuthenticationUnavailableError()
    refused = AuthenticationError(AuthFailureReason.INVALID_SIGNATURE)

    assert unavailable.reason is not refused.reason


def test_insufficient_scope_error_carries_the_required_scope() -> None:
    error = InsufficientScopeError("dataset:read")

    assert error.required == "dataset:read"
    assert str(error) == "dataset:read"


def test_insufficient_scope_error_exposes_individual_scope_names() -> None:
    error = InsufficientScopeError("dataset:read dataset:write")

    assert error.required_scopes == frozenset({"dataset:read", "dataset:write"})


def test_insufficient_scope_error_is_not_an_authentication_error() -> None:
    error = InsufficientScopeError("dataset:read")

    assert not isinstance(error, AuthenticationError)


def test_authentication_error_is_not_an_insufficient_scope_error() -> None:
    error = AuthenticationError(AuthFailureReason.INVALID_CLAIM)

    assert not isinstance(error, InsufficientScopeError)
