"""application entry point for the backend."""

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager, suppress
import asyncio
import logging
import os
from typing import Literal

from fastapi import FastAPI
from sqlalchemy.engine import Engine

from hawki_backend.api.v1.health import router as health_router
from hawki_backend.api.v1.query import router as query_router
from hawki_backend.ops.api import router as ops_router
from hawki_backend.ops.service import OpsService
from hawki_backend.ops import snapshots as _snapshots  # noqa: F401 - register routes
from hawki_backend.auth.jwks import JwksKeyProvider, KeyProvider
from hawki_backend.auth.jwt import JwtAuthenticator
from hawki_backend.callbacks import router as callback_router
from hawki_backend.authorization import (
    AuthorizationPort,
    DatasetGrantReader,
    LocalGrantPolicy,
)
from hawki_backend.datasets import (
    DatasetCleanupCoordinator,
    DatasetService,
    QdrantCatalog,
    router as dataset_router,
)
from hawki_backend.persistence.datasets import (
    DatabaseDatasetScopeRegistry,
    DatasetStore,
)
from hawki_backend.persistence.engine import build_engine
from hawki_backend.persistence.grants import DatabaseGrantReader
from hawki_backend.query.settings import (
    DatasetScopeResolver,
    QueryConfigurationError,
    QuerySettings,
    load_query_settings,
)
from hawki_backend.settings import (
    AuthSettings,
    DatabaseSettings,
    load_auth_settings,
    load_database_settings,
)
from hawki_backend.text_ingestion.api import (
    internal_router as text_ingestion_internal_router,
)
from hawki_backend.text_ingestion.api import router as text_ingestion_router
from hawki_backend.text_ingestion.artifacts import TextArtifactStorage
from hawki_backend.text_ingestion.repository import TextOperationRepository
from hawki_backend.text_ingestion.service import TextIngestionService
from hawki_backend.text_ingestion.settings import (
    TextIngestionSettings,
    load_text_ingestion_settings,
    load_sink_settings,
)
from hawki_backend.text_ingestion.vectors import TextVectorDeletion
from hawki_backend.managed_documents.api import (
    router as managed_router,
    internal_router as managed_internal_router,
)
from hawki_backend.managed_documents.service import ManagedService
from hawki_backend.managed_documents.repository import ManagedRepository
from hawki_backend.managed_documents.artifacts import UploadStorage
from hawki_backend.managed_documents.cleanup import ManagedCleanup
from hawki_backend.url_ingestion.api import (
    router as url_router,
    internal_router as url_internal_router,
)
from hawki_backend.url_ingestion.service import UrlIngestionService
from hawki_backend.url_ingestion.repository import UrlRepository
from hawki_backend.url_ingestion import catalog as _catalog  # noqa: F401 - register routes
from hawki_backend.url_ingestion.settings import load_url_ingestion_settings
from hawki_rag_retrieval import QueryDependencies, build_query_dependencies
from hawki_backend.workflow_control.api import router as workflow_router
from hawki_backend.workflow_control.repository import OperationRepository
from hawki_backend.workflow_control.service import WorkflowControl
from hawki_workflow_control.settings import load_settings as load_workflow_settings
from hawki_workflow_control.temporal_client import TemporalControlClient

logger = logging.getLogger(__name__)


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
    text_ingestion_settings: TextIngestionSettings | None = None,
    text_ingestion_service: TextIngestionService | None = None,
    text_ingestion_secret: str | None = None,
    managed_document_service: ManagedService | None = None,
    url_ingestion_service: UrlIngestionService | None = None,
    dataset_service: DatasetService | None = None,
    ops_service: OpsService | None = None,
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
        dataset_service: A pre-built dataset authority, used by tests. When it
            is not supplied and the database dataset source is selected, the
            production service is built.

    Raises:
        AuthConfigurationError: When the environment holds an incomplete or
            invalid authentication configuration.
        DatabaseConfigurationError: When the environment holds an incomplete
            or invalid database configuration.
        QueryConfigurationError: When the environment holds an incomplete or
            invalid query configuration.
    """
    dataset_source = _dataset_source()
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

    dataset_store: DatasetStore | None = None
    scope_resolver: DatasetScopeResolver | None = None
    if dataset_source == "database":
        if owned_engine is None:
            dataset_database = (
                database_settings
                if database_settings is not None
                else load_database_settings()
            )
            if dataset_database is None:
                raise QueryConfigurationError(
                    "HAWKI_BACKEND_QUERY_DATASET_SOURCE=database requires a "
                    "configured database"
                )
            owned_engine = build_engine(dataset_database)
        dataset_store = DatasetStore(owned_engine)
        scope_resolver = DatabaseDatasetScopeRegistry(dataset_store)

    resolved_query_settings = (
        query_settings
        if query_settings is not None
        else load_query_settings(dataset_scope_resolver=scope_resolver)
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
                OperationRepository(owned_engine, dataset_source=dataset_source),
                TemporalControlClient(load_workflow_settings()),
            )

    resolved_text_settings = (
        text_ingestion_settings
        if text_ingestion_settings is not None
        else load_text_ingestion_settings()
    )
    resolved_text_service = text_ingestion_service
    if resolved_text_service is None and resolved_text_settings.enabled:
        if owned_engine is None:
            text_database = database_settings or load_database_settings()
            if text_database is not None:
                owned_engine = build_engine(text_database)
        if owned_engine is not None:
            resolved_text_service = TextIngestionService(
                repository=TextOperationRepository(owned_engine),
                artifacts=TextArtifactStorage(resolved_text_settings.shared_root),
                vectors=TextVectorDeletion(resolved_text_settings),
                settings=resolved_text_settings,
                policy=resolved_policy,
                query_settings=resolved_query_settings,
                workflow_control=resolved_workflow_control,
                chunk_size=_positive_int("CHUNK_SIZE", 1200),
                chunk_overlap=_positive_int("CHUNK_OVERLAP_SIZE", 250, allow_zero=True),
                batch_size=_positive_int("INGEST_BATCH_SIZE", 64),
            )
    text_secret = (
        text_ingestion_secret if text_ingestion_secret is not None else control_secret
    )
    resolved_managed_service = managed_document_service
    if resolved_managed_service is None and os.environ.get(
        "HAWKI_BACKEND_MANAGED_DOCUMENTS_ENABLED", "false"
    ).lower() in {"1", "true", "yes"}:
        if owned_engine is None:
            managed_database = database_settings or load_database_settings()
            if managed_database is not None:
                owned_engine = build_engine(managed_database)
        if owned_engine is not None:
            managed_settings = load_sink_settings()
            resolved_managed_service = ManagedService(
                repository=ManagedRepository(owned_engine),
                storage=UploadStorage(managed_settings.shared_root),
                policy=resolved_policy,
                query_settings=resolved_query_settings,
                control=resolved_workflow_control,
                cleanup=ManagedCleanup(TextVectorDeletion(managed_settings)),
            )

    resolved_url_service = url_ingestion_service
    url_settings = load_url_ingestion_settings()
    if resolved_url_service is None and url_settings.enabled:
        if owned_engine is None:
            url_database = database_settings or load_database_settings()
            if url_database is not None:
                owned_engine = build_engine(url_database)
        if owned_engine is not None:
            sink_settings = load_sink_settings()
            resolved_url_service = UrlIngestionService(
                repository=UrlRepository(owned_engine),
                policy=resolved_policy,
                query_settings=resolved_query_settings,
                control=resolved_workflow_control,
                settings=url_settings,
                cleanup=ManagedCleanup(TextVectorDeletion(sink_settings)),
                neo4j_database=os.environ.get("NEO4J_DATABASE", "neo4j"),
            )

    resolved_dataset_service = dataset_service
    if resolved_dataset_service is None and dataset_store is not None:
        resolved_dataset_service = DatasetService(
            dataset_store,
            QdrantCatalog.from_env(),
            embedding_provider=os.environ.get(
                "HAWKI_BACKEND_DATASET_EMBEDDING_PROVIDER", "ollama"
            ),
            embedding_model=os.environ.get(
                "HAWKI_BACKEND_DATASET_EMBEDDING_MODEL", "bge-m3"
            ),
            graph_enabled=_dataset_graph_default(),
            cleanup=DatasetCleanupCoordinator(
                dataset_store,
                managed_documents=resolved_managed_service,
                text_ingestions=resolved_text_service,
                url_sources=resolved_url_service,
            ),
        )

    @asynccontextmanager
    async def lifespan(application: FastAPI) -> AsyncIterator[None]:
        """Recover archived deletions and release the resources we own."""
        cleanup_task: asyncio.Task[None] | None = None
        url_task: asyncio.Task[None] | None = None
        if (
            os.environ.get("HAWKI_BACKEND_REQUIRE_READY_SCHEMA", "false").lower()
            == "true"
        ):
            from hawki_backend.readiness import check_database

            try:
                await asyncio.to_thread(check_database, os.environ)
            except Exception:
                raise RuntimeError(
                    "Backend schema readiness failed before cleanup startup"
                ) from None
        if (
            resolved_dataset_service is not None
            and os.environ.get("HAWKI_BACKEND_DATASET_CLEANUP_ENABLED", "true").lower()
            == "true"
        ):
            await _reconcile_once(resolved_dataset_service)
            cleanup_task = asyncio.create_task(
                _reconcile_periodically(resolved_dataset_service)
            )
        if (
            resolved_url_service is not None
            and os.environ.get("HAWKI_BACKEND_URL_RECONCILE_ENABLED", "true").lower()
            == "true"
        ):
            url_task = asyncio.create_task(
                _reconcile_urls_periodically(resolved_url_service)
            )
        try:
            yield
        finally:
            for task in (cleanup_task, url_task):
                if task is None:
                    continue
                task.cancel()
                with suppress(asyncio.CancelledError):
                    await task
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
    application.include_router(callback_router)
    application.include_router(query_router, prefix="/v1")
    application.include_router(ops_router, prefix="/v1")
    if resolved_dataset_service is not None:
        application.include_router(dataset_router, prefix="/v1")
    application.include_router(text_ingestion_router, prefix="/v1")
    application.include_router(text_ingestion_internal_router)
    application.include_router(workflow_router)
    application.include_router(managed_router, prefix="/v1")
    application.include_router(managed_internal_router)
    application.include_router(url_router, prefix="/v1")
    application.include_router(url_internal_router)
    application.state.authenticator = resolved_authenticator
    application.state.grant_policy = resolved_policy
    application.state.query_settings = resolved_query_settings
    application.state.query_dependencies = resolved_query_dependencies
    application.state.ops_service = ops_service
    if (
        ops_service is None
        and dataset_store is not None
        and resolved_policy is not None
        and resolved_query_settings is not None
    ):
        application.state.ops_service = OpsService(
            dataset_store,
            resolved_policy,
            resolved_query_settings,
            QdrantCatalog.from_env(),
            owned_engine,
            ManagedRepository(owned_engine),
        )
    application.state.dataset_service = resolved_dataset_service
    application.state.workflow_control = resolved_workflow_control
    application.state.workflow_control_secret = control_secret
    application.state.text_ingestion_service = resolved_text_service
    application.state.text_ingestion_settings = resolved_text_settings
    application.state.text_ingestion_secret = text_secret
    application.state.managed_document_service = resolved_managed_service
    application.state.managed_document_secret = control_secret
    application.state.url_ingestion_service = resolved_url_service
    application.state.url_ingestion_secret = control_secret
    return application


async def _reconcile_once(service: DatasetService) -> None:
    """Attempt one archived-dataset reconciliation without failing startup."""

    try:
        await service.reconcile_cleanup()
    except Exception as error:  # noqa: BLE001 - recovery must not crash the app
        logger.warning(
            "archived dataset reconciliation failed",
            extra={"error_type": type(error).__name__},
        )


async def _reconcile_periodically(service: DatasetService) -> None:
    """Re-drive durable archived rows until the application shuts down."""

    interval = _positive_int("HAWKI_BACKEND_DATASET_CLEANUP_INTERVAL_SECONDS", 30)
    while True:
        await asyncio.sleep(interval)
        await _reconcile_once(service)


async def _reconcile_urls_periodically(service: UrlIngestionService) -> None:
    from hawki_backend.url_ingestion.scheduler import tick

    interval = _positive_int("HAWKI_BACKEND_URL_RECONCILE_INTERVAL_SECONDS", 30)
    while True:
        await asyncio.sleep(interval)
        try:
            await asyncio.to_thread(lambda: asyncio.run(tick(service)))
        except Exception as error:
            logger.warning("URL reconciliation failed error=%s", type(error).__name__)


def _dataset_graph_default() -> bool:
    value = os.environ.get("HAWKI_BACKEND_DATASET_GRAPH_ENABLED", "false").lower()
    if value not in {"true", "false"}:
        raise ValueError("HAWKI_BACKEND_DATASET_GRAPH_ENABLED must be true or false")
    return value == "true"


def _dataset_source() -> Literal["environment", "database"]:
    """Return the selected runtime dataset authority or fail startup."""

    raw = os.environ.get("HAWKI_BACKEND_QUERY_DATASET_SOURCE")
    if raw is None:
        return "environment"
    source = raw.strip().lower()
    if source not in {"environment", "database"}:
        raise QueryConfigurationError(
            "HAWKI_BACKEND_QUERY_DATASET_SOURCE must be environment or database"
        )
    return "database" if source == "database" else "environment"


def _positive_int(name: str, default: int, *, allow_zero: bool = False) -> int:
    """Read one positive integer environment setting or fail the startup."""
    raw = os.environ.get(name)
    if raw is None or raw.strip() == "":
        return default
    try:
        value = int(raw)
    except ValueError:
        raise ValueError(f"{name} must be an integer") from None
    if value < 0 or (value == 0 and not allow_zero):
        raise ValueError(f"{name} must be positive")
    return value


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
