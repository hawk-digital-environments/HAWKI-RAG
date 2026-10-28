"""Tests for the authentication configuration contract."""

import dataclasses
import json
from collections.abc import Mapping

import pytest

from hawki_backend.auth.models import PrincipalKind
from hawki_backend.settings import (
    AuthConfigurationError,
    AuthSettings,
    load_auth_settings,
)

_ISSUER = "https://issuer.example"
_AUDIENCE = "hawki-api"
_JWKS_URL = "https://issuer.example/.well-known/jwks.json"
_CLIENTS = {"hawki-console": "user", "batch-importer": "service"}


def _env(**overrides: str) -> dict[str, str]:
    """Return a complete, valid environment mapping with overrides applied."""
    environment = {
        "HAWKI_BACKEND_AUTH_ISSUER": _ISSUER,
        "HAWKI_BACKEND_AUTH_AUDIENCE": _AUDIENCE,
        "HAWKI_BACKEND_AUTH_JWKS_URL": _JWKS_URL,
        "HAWKI_BACKEND_AUTH_CLIENTS": json.dumps(_CLIENTS),
    }
    environment.update(overrides)
    return environment


def test_no_auth_variables_returns_none() -> None:
    assert load_auth_settings({}) is None


def test_an_unrelated_variable_does_not_configure_authentication() -> None:
    assert load_auth_settings({"PATH": "/usr/bin", "HOME": "/root"}) is None


def test_a_complete_configuration_loads() -> None:
    settings = load_auth_settings(_env())

    assert settings is not None
    assert settings.issuer == _ISSUER
    assert settings.audience == _AUDIENCE
    assert settings.jwks_url == _JWKS_URL


def test_the_client_registry_maps_client_ids_to_principal_kinds() -> None:
    settings = load_auth_settings(_env())

    assert settings is not None
    assert settings.clients == {
        "hawki-console": PrincipalKind.USER,
        "batch-importer": PrincipalKind.SERVICE,
    }


def test_defaults_are_applied_for_optional_variables() -> None:
    settings = load_auth_settings(_env())

    assert settings is not None
    assert settings.algorithms == ("RS256", "ES256")
    assert settings.tenant_claim == "tenant_id"
    assert settings.leeway_seconds == 30
    assert settings.max_token_ttl_seconds == 3600
    assert settings.jwks_cache_ttl_seconds == 600
    assert settings.jwks_min_refresh_seconds == 60
    assert settings.jwks_max_stale_seconds == 3600
    assert settings.jwks_timeout_seconds == 3
    assert settings.jwks_max_bytes == 512 * 1024


def test_a_partially_configured_authentication_fails() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings({"HAWKI_BACKEND_AUTH_ISSUER": _ISSUER})


@pytest.mark.parametrize("suffix", ["ISSUER", "AUDIENCE", "JWKS_URL", "CLIENTS"])
def test_a_missing_required_variable_fails(suffix: str) -> None:
    environment = _env()
    del environment[f"HAWKI_BACKEND_AUTH_{suffix}"]

    with pytest.raises(AuthConfigurationError):
        load_auth_settings(environment)


@pytest.mark.parametrize("suffix", ["ISSUER", "AUDIENCE", "JWKS_URL", "CLIENTS"])
def test_an_empty_required_variable_fails(suffix: str) -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(**{f"HAWKI_BACKEND_AUTH_{suffix}": ""}))


def test_an_unknown_auth_variable_fails() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_ISSUERR=_ISSUER))


def test_a_plain_http_jwks_url_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(
            _env(HAWKI_BACKEND_AUTH_JWKS_URL="http://issuer.example/jwks")
        )


@pytest.mark.parametrize(
    "url",
    [
        "not-a-url",
        "https://",
        "https://issuer.example/jwks#fragment",
        "https://user:secret@issuer.example/jwks",
        "https://issuer example/jwks",
    ],
)
def test_a_malformed_jwks_url_is_rejected(url: str) -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_JWKS_URL=url))


@pytest.mark.parametrize("algorithm", ["none", "HS256", "HS384", "HS512"])
def test_an_unsigned_or_symmetric_algorithm_is_rejected(algorithm: str) -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_ALGORITHMS=algorithm))


def test_an_unknown_algorithm_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_ALGORITHMS="RS512"))


@pytest.mark.parametrize("algorithm", ["RS256", "PS256", "ES256"])
def test_an_allowed_algorithm_is_accepted(algorithm: str) -> None:
    settings = load_auth_settings(_env(HAWKI_BACKEND_AUTH_ALGORITHMS=algorithm))

    assert settings is not None
    assert settings.algorithms == (algorithm,)


def test_several_allowed_algorithms_are_accepted() -> None:
    settings = load_auth_settings(
        _env(HAWKI_BACKEND_AUTH_ALGORITHMS="RS256,ES256,PS256")
    )

    assert settings is not None
    assert settings.algorithms == ("RS256", "ES256", "PS256")


def test_a_repeated_algorithm_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_ALGORITHMS="RS256,RS256"))


def test_an_empty_algorithm_list_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_ALGORITHMS=""))


@pytest.mark.parametrize(
    "registry",
    [
        "not json",
        "[]",
        '"a string"',
        "{}",
    ],
)
def test_a_malformed_client_registry_is_rejected(registry: str) -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_CLIENTS=registry))


def test_an_unknown_principal_kind_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(
            _env(HAWKI_BACKEND_AUTH_CLIENTS=json.dumps({"hawki-console": "admin"}))
        )


def test_a_non_string_principal_kind_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(
            _env(HAWKI_BACKEND_AUTH_CLIENTS=json.dumps({"hawki-console": 1}))
        )


def test_a_duplicate_client_definition_is_rejected() -> None:
    registry = '{"hawki-console": "user", "hawki-console": "service"}'

    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_CLIENTS=registry))


def test_a_blank_client_identifier_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_CLIENTS=json.dumps({" ": "user"})))


def test_an_empty_tenant_claim_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_TENANT_CLAIM=""))


@pytest.mark.parametrize("claim", ["1tenant", "tenant claim", "a" * 65])
def test_an_invalid_tenant_claim_name_is_rejected(claim: str) -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_TENANT_CLAIM=claim))


@pytest.mark.parametrize("claim", ["org.id", "tenant-claim", "_private"])
def test_a_valid_tenant_claim_name_is_accepted(claim: str) -> None:
    settings = load_auth_settings(_env(HAWKI_BACKEND_AUTH_TENANT_CLAIM=claim))

    assert settings is not None
    assert settings.tenant_claim == claim


def test_the_default_tenant_claim_name_is_accepted() -> None:
    settings = load_auth_settings(_env())

    assert settings is not None
    assert settings.tenant_claim == "tenant_id"


@pytest.mark.parametrize(
    "suffix",
    [
        "LEEWAY_SECONDS",
        "MAX_TOKEN_TTL_SECONDS",
        "JWKS_CACHE_TTL_SECONDS",
        "JWKS_MIN_REFRESH_SECONDS",
        "JWKS_MAX_STALE_SECONDS",
        "JWKS_TIMEOUT_SECONDS",
        "JWKS_MAX_BYTES",
    ],
)
def test_a_non_numeric_value_is_rejected(suffix: str) -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(**{f"HAWKI_BACKEND_AUTH_{suffix}": "not-a-number"}))


@pytest.mark.parametrize(
    "suffix",
    [
        "LEEWAY_SECONDS",
        "MAX_TOKEN_TTL_SECONDS",
        "JWKS_CACHE_TTL_SECONDS",
        "JWKS_MIN_REFRESH_SECONDS",
        "JWKS_MAX_STALE_SECONDS",
        "JWKS_TIMEOUT_SECONDS",
        "JWKS_MAX_BYTES",
    ],
)
def test_a_negative_value_is_rejected(suffix: str) -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(**{f"HAWKI_BACKEND_AUTH_{suffix}": "-1"}))


def test_a_zero_jwks_timeout_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_JWKS_TIMEOUT_SECONDS="0"))


def test_an_excessive_jwks_timeout_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_JWKS_TIMEOUT_SECONDS="31"))


def test_the_largest_allowed_jwks_timeout_is_accepted() -> None:
    settings = load_auth_settings(_env(HAWKI_BACKEND_AUTH_JWKS_TIMEOUT_SECONDS="30"))

    assert settings is not None
    assert settings.jwks_timeout_seconds == 30


def test_a_zero_leeway_is_accepted() -> None:
    settings = load_auth_settings(_env(HAWKI_BACKEND_AUTH_LEEWAY_SECONDS="0"))

    assert settings is not None
    assert settings.leeway_seconds == 0


def test_an_excessive_leeway_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_LEEWAY_SECONDS="301"))


def test_a_zero_max_token_ttl_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_MAX_TOKEN_TTL_SECONDS="0"))


def test_an_excessive_max_token_ttl_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_MAX_TOKEN_TTL_SECONDS="86401"))


def test_the_maximum_allowed_token_ttl_is_accepted() -> None:
    settings = load_auth_settings(
        _env(HAWKI_BACKEND_AUTH_MAX_TOKEN_TTL_SECONDS="86400")
    )

    assert settings is not None
    assert settings.max_token_ttl_seconds == 86400


def test_a_zero_cache_ttl_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_JWKS_CACHE_TTL_SECONDS="0"))


def test_a_zero_minimum_refresh_interval_is_rejected() -> None:
    with pytest.raises(AuthConfigurationError):
        load_auth_settings(_env(HAWKI_BACKEND_AUTH_JWKS_MIN_REFRESH_SECONDS="0"))


def test_a_zero_maximum_stale_interval_is_accepted() -> None:
    settings = load_auth_settings(_env(HAWKI_BACKEND_AUTH_JWKS_MAX_STALE_SECONDS="0"))

    assert settings is not None
    assert settings.jwks_max_stale_seconds == 0


def test_settings_are_immutable() -> None:
    settings = load_auth_settings(_env())

    assert settings is not None
    with pytest.raises(dataclasses.FrozenInstanceError):
        settings.issuer = "https://other.example"  # type: ignore[misc]


def test_the_client_registry_cannot_be_mutated() -> None:
    settings = load_auth_settings(_env())

    assert settings is not None
    with pytest.raises(TypeError):
        settings.clients["injected"] = PrincipalKind.USER  # type: ignore[index]


def test_the_algorithm_list_cannot_be_mutated() -> None:
    settings = load_auth_settings(_env())

    assert settings is not None
    assert isinstance(settings.algorithms, tuple)


def test_an_injected_mapping_is_used_instead_of_the_process_environment(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("HAWKI_BACKEND_AUTH_ISSUER", "https://from-process.example")
    monkeypatch.setenv("HAWKI_BACKEND_AUTH_AUDIENCE", "from-process")
    monkeypatch.setenv("HAWKI_BACKEND_AUTH_JWKS_URL", "https://from-process/jwks")
    monkeypatch.setenv("HAWKI_BACKEND_AUTH_CLIENTS", json.dumps(_CLIENTS))

    assert load_auth_settings({}) is None

    settings = load_auth_settings(_env())

    assert settings is not None
    assert settings.issuer == _ISSUER


def test_the_loader_reads_the_process_environment_when_none_is_injected(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    for name, value in _env().items():
        monkeypatch.setenv(name, value)

    settings = load_auth_settings()

    assert settings is not None
    assert settings.issuer == _ISSUER


def test_a_bare_mapping_is_accepted() -> None:
    environment: Mapping[str, str] = _env()

    settings = load_auth_settings(environment)

    assert settings is not None


def test_auth_settings_is_an_immutable_dataclass() -> None:
    assert issubclass(AuthConfigurationError, Exception)
    assert AuthSettings.__dataclass_params__.frozen is True
    assert AuthSettings.__dataclass_params__.eq is True
