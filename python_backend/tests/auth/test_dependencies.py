"""Tests for the FastAPI authentication dependency boundary."""

import asyncio
import dataclasses
import os
from typing import Annotated

import httpx
import jwt
import pytest
from fastapi import Depends, FastAPI
from cryptography.hazmat.primitives.asymmetric import rsa as rsa_module

from hawki_backend.auth.dependencies import (
    get_authenticated_principal,
    require_scopes,
)
from hawki_backend.auth.errors import AuthenticationUnavailableError
from hawki_backend.auth.jwks import JwksKeyProvider, StaticKeyProvider
from hawki_backend.auth.jwt import JwtAuthenticator
from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.main import create_app
from hawki_backend.settings import AuthConfigurationError, AuthSettings

from .conftest import EC_KID, RSA_KID, ControllableClock

_ISSUER = "https://issuer.example"
_AUDIENCE = "hawki-api"
_USER_CLIENT = "hawki-console"
_SERVICE_CLIENT = "batch-importer"
_TENANT = "tenant-1"


def _settings(auth_settings: AuthSettings) -> AuthSettings:
    """Return settings that know both a user client and a service client."""
    return dataclasses.replace(
        auth_settings,
        clients={
            _USER_CLIENT: PrincipalKind.USER,
            _SERVICE_CLIENT: PrincipalKind.SERVICE,
        },
    )


def _claims(clock: ControllableClock, **overrides: object) -> dict[str, object]:
    """Return a valid claim set, with overrides applied."""
    claims: dict[str, object] = {
        "iss": _ISSUER,
        "aud": _AUDIENCE,
        "sub": "user-42",
        "exp": int(clock()) + 300,
        "azp": _USER_CLIENT,
        "tenant_id": _TENANT,
    }
    claims.update(overrides)
    return claims


def _token(
    claims: dict[str, object],
    key: object,
    algorithm: str = "RS256",
    key_id: str = RSA_KID,
) -> str:
    """Return a signed bearer token."""
    return jwt.encode(claims, key, algorithm=algorithm, headers={"kid": key_id})


def _authenticator(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> JwtAuthenticator:
    """Build an authenticator backed by static keys."""
    return JwtAuthenticator(
        _settings(auth_settings),
        key_provider=StaticKeyProvider(
            {RSA_KID: rsa_public_key, EC_KID: ec_public_key}
        ),
        clock=clock,
    )


def _scoped_app(authenticator: JwtAuthenticator | None, *scopes: str) -> FastAPI:
    """Build a test-only application that requires the given scopes."""
    application = FastAPI()
    application.state.authenticator = authenticator

    @application.get("/scoped")
    def scoped(
        principal: Annotated[AuthenticatedPrincipal, Depends(require_scopes(*scopes))],
    ) -> dict[str, str]:
        """Return the authenticated subject."""
        return {"subject": principal.subject}

    return application


def _test_app(authenticator: JwtAuthenticator | None) -> FastAPI:
    """Build a test-only application that uses the authentication boundary.

    The production application is never given a protected route.
    """
    application = FastAPI()
    application.state.authenticator = authenticator

    @application.get("/protected")
    def protected(
        principal: Annotated[
            AuthenticatedPrincipal, Depends(get_authenticated_principal)
        ],
    ) -> dict[str, str]:
        """Return the authenticated subject."""
        return {"subject": principal.subject}

    @application.get("/scoped")
    def scoped(
        principal: Annotated[
            AuthenticatedPrincipal, Depends(require_scopes("rag:query"))
        ],
    ) -> dict[str, str]:
        """Return the authenticated subject when it holds the scope."""
        return {"subject": principal.subject}

    return application


async def _get(
    application: FastAPI, path: str, token: str | None = None
) -> httpx.Response:
    """Issue a request against a test application."""
    headers = {"Authorization": f"Bearer {token}"} if token is not None else {}
    transport = httpx.ASGITransport(app=application)
    async with httpx.AsyncClient(
        transport=transport, base_url="http://testserver", headers=headers
    ) as client:
        return await client.get(path)


async def _get_raw(
    application: FastAPI, path: str, headers: list[tuple[str, str]]
) -> httpx.Response:
    """Issue a request with the given headers."""
    transport = httpx.ASGITransport(app=application)
    async with httpx.AsyncClient(
        transport=transport, base_url="http://testserver", headers=headers
    ) as client:
        return await client.get(path)


async def _run_lifespan(application: FastAPI) -> None:
    """Start and stop the application lifespan over the raw ASGI protocol."""
    inbox: asyncio.Queue[dict[str, object]] = asyncio.Queue()
    started = asyncio.Event()
    stopped = asyncio.Event()

    async def receive() -> dict[str, object]:
        return await inbox.get()

    async def send(message: dict[str, object]) -> None:
        if message["type"] == "lifespan.startup.complete":
            started.set()
        elif message["type"] == "lifespan.shutdown.complete":
            stopped.set()

    task = asyncio.create_task(
        application(
            {
                "type": "lifespan",
                "asgi": {"version": "3.0", "spec_version": "2.0"},
            },
            receive,
            send,
        )
    )
    await inbox.put({"type": "lifespan.startup"})
    await asyncio.wait_for(started.wait(), timeout=5)
    await inbox.put({"type": "lifespan.shutdown"})
    await asyncio.wait_for(stopped.wait(), timeout=5)
    await task


# --------------------------------------------------------------------- wiring


def test_an_application_without_authentication_starts(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    for name in list(os.environ):
        if name.startswith("HAWKI_BACKEND_AUTH_"):
            monkeypatch.delenv(name, raising=False)

    application = create_app()

    assert application.state.authenticator is None


def test_injected_settings_build_an_authenticator(
    auth_settings: AuthSettings,
    rsa_public_key: object,
) -> None:
    provider = StaticKeyProvider({RSA_KID: rsa_public_key})

    application = create_app(
        auth_settings=_settings(auth_settings), key_provider=provider
    )

    assert isinstance(application.state.authenticator, JwtAuthenticator)


def test_injected_settings_without_a_key_provider_build_a_jwks_provider(
    auth_settings: AuthSettings,
) -> None:
    application = create_app(auth_settings=_settings(auth_settings))

    assert isinstance(application.state.authenticator, JwtAuthenticator)


def test_a_partial_environment_configuration_fails_application_creation(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("HAWKI_BACKEND_AUTH_ISSUER", _ISSUER)

    with pytest.raises(AuthConfigurationError):
        create_app()


def test_an_invalid_environment_configuration_fails_application_creation(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("HAWKI_BACKEND_AUTH_ISSUER", _ISSUER)
    monkeypatch.setenv("HAWKI_BACKEND_AUTH_AUDIENCE", _AUDIENCE)
    monkeypatch.setenv("HAWKI_BACKEND_AUTH_JWKS_URL", "http://issuer.example/jwks")
    monkeypatch.setenv("HAWKI_BACKEND_AUTH_CLIENTS", '{"client": "user"}')

    with pytest.raises(AuthConfigurationError):
        create_app()


def test_injected_settings_do_not_read_the_process_environment(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("HAWKI_BACKEND_AUTH_ISSUER", _ISSUER)

    application = create_app(
        auth_settings=_settings(auth_settings),
        key_provider=StaticKeyProvider({RSA_KID: rsa_public_key}),
    )

    assert isinstance(application.state.authenticator, JwtAuthenticator)


def test_an_injected_key_provider_is_not_closed_by_the_application(
    auth_settings: AuthSettings, rsa_public_key: object
) -> None:
    provider = StaticKeyProvider({RSA_KID: rsa_public_key})

    create_app(auth_settings=_settings(auth_settings), key_provider=provider)

    assert provider.get_key(RSA_KID, "RS256") is rsa_public_key


def test_the_application_closes_the_key_provider_it_owns(
    auth_settings: AuthSettings,
) -> None:
    application = create_app(auth_settings=_settings(auth_settings))
    # The provider the application built is not published, so the test reads
    # it back to observe that the application closes what it owns.
    provider = application.state.authenticator._key_provider
    assert isinstance(provider, JwksKeyProvider)
    client = provider._client

    async def check() -> None:
        await _run_lifespan(application)

    asyncio.run(check())

    assert client.is_closed


def test_the_application_does_not_close_an_injected_key_provider(
    auth_settings: AuthSettings, rsa_public_key: object
) -> None:
    provider = StaticKeyProvider({RSA_KID: rsa_public_key})
    application = create_app(
        auth_settings=_settings(auth_settings), key_provider=provider
    )

    async def check() -> None:
        await _run_lifespan(application)

    asyncio.run(check())

    assert provider.get_key(RSA_KID, "RS256") is rsa_public_key


# ---------------------------------------------------------------- no credentials


def test_a_missing_authorization_header_is_refused(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )

    response = asyncio.run(_get(application, "/protected"))

    assert response.status_code == 401
    assert response.json() == {"detail": "authentication_required"}
    assert response.headers["www-authenticate"] == "Bearer"


@pytest.mark.parametrize(
    "header",
    [
        "Basic dXNlcjpwYXNzd29yZA==",
        "Bearer",
        "Bearer ",
        "Bearer a b",
        "bearerx token",
        "token",
    ],
)
def test_an_unusable_authorization_header_is_refused(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
    header: str,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )

    response = asyncio.run(
        _get_raw(application, "/protected", [("Authorization", header)])
    )

    assert response.status_code == 401
    assert response.json() == {"detail": "invalid_token"}
    assert response.headers["www-authenticate"] == 'Bearer error="invalid_token"'


def test_multiple_authorization_headers_are_refused(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )

    response = asyncio.run(
        _get_raw(
            application,
            "/protected",
            [("Authorization", "Bearer one"), ("Authorization", "Bearer two")],
        )
    )

    assert response.status_code == 401
    assert response.json() == {"detail": "invalid_token"}


# ----------------------------------------------------------------- valid tokens


def test_a_valid_user_token_returns_the_principal(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )
    token = _token(_claims(clock), rsa_signing_key)

    response = asyncio.run(_get(application, "/protected", token))

    assert response.status_code == 200
    assert response.json() == {"subject": "user-42"}


def test_a_valid_service_token_returns_the_principal(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )
    token = _token(_claims(clock, azp=_SERVICE_CLIENT), rsa_signing_key)

    response = asyncio.run(_get(application, "/protected", token))

    assert response.status_code == 200


def test_a_valid_ec_token_returns_the_principal(
    auth_settings: AuthSettings,
    ec_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )
    token = _token(_claims(clock), ec_signing_key, algorithm="ES256", key_id=EC_KID)

    response = asyncio.run(_get(application, "/protected", token))

    assert response.status_code == 200


# --------------------------------------------------------------- invalid tokens


@pytest.mark.parametrize(
    "claims_override",
    [
        {"exp": 1},
        {"iss": "https://other.example"},
        {"aud": "other-api"},
    ],
)
def test_a_refused_token_is_reported_as_an_invalid_token(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
    claims_override: dict[str, object],
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )
    token = _token(_claims(clock, **claims_override), rsa_signing_key)

    response = asyncio.run(_get(application, "/protected", token))

    assert response.status_code == 401
    assert response.json() == {"detail": "invalid_token"}
    assert response.headers["www-authenticate"] == 'Bearer error="invalid_token"'


def test_a_malformed_token_is_reported_as_an_invalid_token(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )

    response = asyncio.run(_get(application, "/protected", "not-a-token"))

    assert response.status_code == 401
    assert response.json() == {"detail": "invalid_token"}


def test_a_token_with_an_unknown_key_is_reported_as_an_invalid_token(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )
    token = _token(_claims(clock), rsa_signing_key, key_id="rotated")

    response = asyncio.run(_get(application, "/protected", token))

    assert response.status_code == 401
    assert response.json() == {"detail": "invalid_token"}


def test_a_bad_signature_is_reported_as_an_invalid_token(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    other = rsa_module.generate_private_key(public_exponent=65537, key_size=2048)
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )
    token = _token(_claims(clock), other)

    response = asyncio.run(_get(application, "/protected", token))

    assert response.status_code == 401
    assert response.json() == {"detail": "invalid_token"}


# ------------------------------------------------------------------- unavailable


def test_an_unavailable_key_source_is_reported_as_unavailable(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    clock: ControllableClock,
) -> None:
    class UnavailableProvider:
        def get_key(self, kid: str, alg: str) -> object:
            raise AuthenticationUnavailableError()

    application = _test_app(
        JwtAuthenticator(
            _settings(auth_settings),
            key_provider=UnavailableProvider(),
            clock=clock,
        )
    )
    token = _token(_claims(clock), rsa_signing_key)

    response = asyncio.run(_get(application, "/protected", token))

    assert response.status_code == 503
    assert response.json() == {"detail": "authentication_unavailable"}
    assert "www-authenticate" not in response.headers


def test_an_unavailable_key_source_is_not_reported_as_an_invalid_token(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    clock: ControllableClock,
) -> None:
    class UnavailableProvider:
        def get_key(self, kid: str, alg: str) -> object:
            raise AuthenticationUnavailableError()

    application = _test_app(
        JwtAuthenticator(
            _settings(auth_settings),
            key_provider=UnavailableProvider(),
            clock=clock,
        )
    )
    token = _token(_claims(clock), rsa_signing_key)

    response = asyncio.run(_get(application, "/protected", token))

    assert response.status_code != 401
    assert response.json() != {"detail": "invalid_token"}


def test_an_application_without_authentication_is_unavailable(
    auth_settings: AuthSettings,
) -> None:
    application = _test_app(None)

    response = asyncio.run(_get(application, "/protected", "any-token"))

    assert response.status_code == 503
    assert response.json() == {"detail": "authentication_unavailable"}
    assert "www-authenticate" not in response.headers


# ----------------------------------------------------------------------- scopes


def test_a_present_scope_is_accepted(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )
    token = _token(_claims(clock, scope="rag:query"), rsa_signing_key)

    response = asyncio.run(_get(application, "/scoped", token))

    assert response.status_code == 200


def test_a_missing_scope_is_refused_with_insufficient_scope(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )
    token = _token(_claims(clock, scope="rag:read"), rsa_signing_key)

    response = asyncio.run(_get(application, "/scoped", token))

    assert response.status_code == 403
    assert response.json() == {"detail": "insufficient_scope"}
    assert (
        response.headers["www-authenticate"]
        == 'Bearer error="insufficient_scope", scope="rag:query"'
    )


def test_a_missing_scope_challenge_does_not_reveal_the_held_scopes(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )
    token = _token(_claims(clock, scope="rag:read rag:write"), rsa_signing_key)

    response = asyncio.run(_get(application, "/scoped", token))

    assert "rag:read" not in response.headers["www-authenticate"]
    assert "rag:write" not in response.headers["www-authenticate"]


def test_every_required_scope_must_be_present(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _scoped_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock),
        "rag:query",
        "rag:ingest",
    )
    token = _token(_claims(clock, scope="rag:query rag:ingest"), rsa_signing_key)

    response = asyncio.run(_get(application, "/scoped", token))

    assert response.status_code == 200
    assert response.headers.get("www-authenticate") is None


def test_a_partially_held_scope_set_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _scoped_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock),
        "rag:query",
        "rag:ingest",
    )
    token = _token(_claims(clock, scope="rag:query"), rsa_signing_key)

    response = asyncio.run(_get(application, "/scoped", token))

    assert response.status_code == 403
    assert response.json() == {"detail": "insufficient_scope"}


def test_the_scope_challenge_is_deterministic(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _scoped_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock),
        "rag:query",
        "rag:ingest",
    )
    token = _token(_claims(clock), rsa_signing_key)

    first = asyncio.run(_get(application, "/scoped", token))
    second = asyncio.run(_get(application, "/scoped", token))

    assert first.headers["www-authenticate"] == second.headers["www-authenticate"]
    assert (
        first.headers["www-authenticate"]
        == 'Bearer error="insufficient_scope", scope="rag:query rag:ingest"'
    )


def test_no_required_scopes_accepts_any_authenticated_principal(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _scoped_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )
    token = _token(_claims(clock), rsa_signing_key)

    response = asyncio.run(_get(application, "/scoped", token))

    assert response.status_code == 200


def test_a_missing_scope_is_not_reported_as_an_unauthenticated_request(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )

    response = asyncio.run(_get(application, "/scoped"))

    assert response.status_code == 401
    assert response.json() == {"detail": "authentication_required"}


# ----------------------------------------------------------------------- health


def test_health_stays_public(
    auth_settings: AuthSettings, rsa_public_key: object
) -> None:
    application = create_app(
        auth_settings=_settings(auth_settings),
        key_provider=StaticKeyProvider({RSA_KID: rsa_public_key}),
    )

    async def request_health() -> httpx.Response:
        transport = httpx.ASGITransport(app=application)
        async with httpx.AsyncClient(
            transport=transport, base_url="http://testserver"
        ) as client:
            return await client.get("/v1/health")

    response = asyncio.run(request_health())

    assert response.status_code == 200
    assert response.json() == {"status": "ok"}


def test_the_production_application_exposes_only_health() -> None:
    from hawki_backend.main import app

    assert set(app.openapi()["paths"]) == {"/v1/health"}


# ---------------------------------------------------------------------- logging


def test_no_failure_logs_the_token_or_the_header(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    ec_public_key: object,
    clock: ControllableClock,
    caplog: pytest.LogCaptureFixture,
) -> None:
    application = _test_app(
        _authenticator(auth_settings, rsa_public_key, ec_public_key, clock)
    )
    token = _token(_claims(clock, exp=1), rsa_signing_key)

    response = asyncio.run(_get(application, "/protected", token))

    assert response.status_code == 401
    assert caplog.records == []
    assert token not in caplog.text
    assert "Authorization" not in caplog.text
    assert "user-42" not in caplog.text
