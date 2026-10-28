"""application entry point for the backend."""

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI

from hawki_backend.api.v1.health import router as health_router
from hawki_backend.auth.jwks import JwksKeyProvider, KeyProvider
from hawki_backend.auth.jwt import JwtAuthenticator
from hawki_backend.settings import AuthSettings, load_auth_settings


def create_app(
    *,
    auth_settings: AuthSettings | None = None,
    key_provider: KeyProvider | None = None,
) -> FastAPI:
    """Create an application with a health endpoint and an auth boundary.

    Args:
        auth_settings: The authentication configuration. When it is not
            supplied the configuration is read from the environment, and an
            environment without authentication settings leaves the
            application without an authentication boundary.
        key_provider: The source of signing keys. When it is not supplied a
            JWKS provider is built from the settings, and the application
            owns and closes it.

    Raises:
        AuthConfigurationError: When the environment holds an incomplete or
            invalid authentication configuration.
    """
    settings = auth_settings if auth_settings is not None else load_auth_settings()
    authenticator, owned_provider = _build_authenticator(settings, key_provider)

    @asynccontextmanager
    async def lifespan(application: FastAPI) -> AsyncIterator[None]:
        """Release the resources the application owns."""
        yield
        if owned_provider is not None:
            owned_provider.close()

    application = FastAPI(
        title="HAWKI Backend",
        version="0.1.0",
        openapi_url=None,
        docs_url=None,
        redoc_url=None,
        lifespan=lifespan,
    )
    application.include_router(health_router, prefix="/v1")
    application.state.authenticator = authenticator
    return application


def _build_authenticator(
    settings: AuthSettings | None, key_provider: KeyProvider | None
) -> tuple[JwtAuthenticator | None, JwksKeyProvider | None]:
    """Build the authenticator and the key provider the application owns."""
    if settings is None:
        return None, None
    if key_provider is not None:
        return JwtAuthenticator(settings, key_provider), None
    provider = JwksKeyProvider(settings)
    return JwtAuthenticator(settings, provider), provider


app = create_app()
