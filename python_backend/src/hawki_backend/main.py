"""application entry point for the backend."""

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
import os

from fastapi import FastAPI
from sqlalchemy.engine import Engine

from hawki_backend.api.v1.health import router as health_router
from hawki_backend.api.v1.query import router as query_router
from hawki_backend.auth.jwks import JwksKeyProvider, KeyProvider
from hawki_backend.auth.jwt import JwtAuthenticator
from hawki_backend.authorization import (
    AuthorizationPort,
    DatasetGrantReader,
    LocalGrantPolicy,
)
from hawki_backend.persistence.engine import build_engine
from hawki_backend.persistence.grants import DatabaseGrantReader
from hawki_backend.query.settings import QuerySettings, load_query_settings
from hawki_backend.settings import (
    AuthSettings,
    DatabaseSettings,
    load_auth_settings,
    load_database_settings,
)
from hawki_rag_retrieval import QueryDependencies, build_query_dependencies
from hawki_backend.workflow_control.api import router as workflow_router
from hawki_backend.workflow_control.repository import OperationRepository
from hawki_backend.workflow_control.service import WorkflowControl
from hawki_workflow_control.settings import load_settings as load_workflow_settings
from hawki_workflow_control.temporal_client import TemporalControlClient


def create_app(
    *,
    auth_settings: AuthSettings | None = None,
    key_provider: KeyProvider | None = None,
    authenticator: JwtAuthenticator | None = None,
    database_settings: DatabaseSettings | None = None,
    grant_reader: DatasetGrantReader | None = None,
    policy: AuthorizationPort | None = None,
    query_settings: QuerySettings | None = None,
    query_dependencies: QueryDependencies | None = None,
    workflow_control: WorkflowControl | None = None,
    workflow_control_secret: str | None = None,
) -> FastAPI:
    """Create an application with health, authentication and query surfaces.

    Args:
        auth_settings: The authentication configuration. When it is not
            supplied the configuration is read from the environment, and an
            environment without authentication settings leaves the
            application without an authentication boundary.
        key_provider: The source of signing keys. When it is not supplied a
            JWKS provider is built from the settings, and the application
            owns and closes it.
        authenticator: A pre-built authenticator, used by tests. When it is
            supplied, no authentication configuration is loaded.
        database_settings: The persistence configuration. When it is not
            supplied the configuration is read from the environment.
        grant_reader: A pre-built grant reader, used by tests. When it is
            supplied, no database engine is built.
        policy: A pre-built authorization policy, used by tests. When it is
            supplied, it takes precedence over ``grant_reader``.
        query_settings: The trusted query scope registry. When it is not
            supplied the configuration is read from the environment.
        query_dependencies: The retrieval dependencies, used by tests. When it
            is not supplied and query settings are configured, the shared
            production composition is built.

    Raises:
        AuthConfigurationError: When the environment holds an incomplete or
            invalid authentication configuration.
        DatabaseConfigurationError: When the environment holds an incomplete
            or invalid database configuration.
        QueryConfigurationError: When the environment holds an incomplete or
            invalid query configuration.
    """
    if authenticator is not None:
        resolved_authenticator = authenticator
        owned_provider = None
    else:
        resolved_authenticator, owned_provider = _build_authenticator(
            auth_settings,
            key_provider,
        )

    resolved_policy = policy
    owned_engine: Engine | None = None
    if resolved_policy is None:
        reader = grant_reader
        if reader is None:
            settings = (
                database_settings
                if database_settings is not None
                else load_database_settings()
            )
            if settings is not None:
                owned_engine = build_engine(settings)
                reader = DatabaseGrantReader(owned_engine)
        if reader is not None:
            resolved_policy = LocalGrantPolicy(reader)

    resolved_query_settings = (
        query_settings if query_settings is not None else load_query_settings()
    )
    resolved_query_dependencies = query_dependencies
    if resolved_query_settings is not None and resolved_query_dependencies is None:
        resolved_query_dependencies = build_query_dependencies()

    resolved_workflow_control = workflow_control
    control_secret = (
        workflow_control_secret
        if workflow_control_secret is not None
        else os.environ.get("HAWKI_RAG_WORKER_CALLBACK_SECRET", "")
    )
    if resolved_workflow_control is None and os.environ.get(
        "HAWKI_WORKFLOW_CONTROL_ENABLED", ""
    ).lower() in {"1", "true", "yes"}:
        if owned_engine is None:
            control_database = database_settings or load_database_settings()
            if control_database is not None:
                owned_engine = build_engine(control_database)
        if owned_engine is not None and control_secret:
            resolved_workflow_control = WorkflowControl(
                OperationRepository(owned_engine),
                TemporalControlClient(load_workflow_settings()),
            )

    @asynccontextmanager
    async def lifespan(application: FastAPI) -> AsyncIterator[None]:
        """Release the resources the application owns."""
        yield
        if owned_provider is not None:
            owned_provider.close()
        if owned_engine is not None:
            owned_engine.dispose()

    application = FastAPI(
        title="HAWKI Backend",
        version="0.1.0",
        openapi_url=None,
        docs_url=None,
        redoc_url=None,
        lifespan=lifespan,
    )
    application.include_router(health_router, prefix="/v1")
    application.include_router(query_router, prefix="/v1")
    application.include_router(workflow_router)
    application.state.authenticator = resolved_authenticator
    application.state.grant_policy = resolved_policy
    application.state.query_settings = resolved_query_settings
    application.state.query_dependencies = resolved_query_dependencies
    application.state.workflow_control = resolved_workflow_control
    application.state.workflow_control_secret = control_secret
    return application


def _build_authenticator(
    settings: AuthSettings | None,
    key_provider: KeyProvider | None,
) -> tuple[JwtAuthenticator | None, JwksKeyProvider | None]:
    """Build the authenticator and the key provider the application owns."""
    if settings is None:
        settings = load_auth_settings()
    if settings is None:
        return None, None
    if key_provider is not None:
        return JwtAuthenticator(settings, key_provider), None
    provider = JwksKeyProvider(settings)
    return JwtAuthenticator(settings, provider), provider


app = create_app()
