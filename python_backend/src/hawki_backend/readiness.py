"""Bounded read-only operational probes, separate from /v1/health liveness.

Running this module is explicit. Importing it never opens a connection, imports
hawki_backend.main, starts cleanup, writes a file, or performs a migration.
"""

import argparse
import asyncio
import hashlib
import hmac
import json
import os
from pathlib import Path
import time
from datetime import timedelta
from collections.abc import Mapping
from typing import Any

import httpx
import sqlalchemy as sa

from .deployment import REQUIRED_REVISION, configuration_checks, enabled
from .persistence.engine import build_engine
from .settings import load_auth_settings, load_database_settings


def required_tables(env):
    from .persistence.schema import datasets, dataset_grants, dataset_grant_tokens
    from .workflow_control.schema import operations
    from .text_ingestion.schema import ingest_text_operations, ingest_text_events
    from .managed_documents.schema import TABLES as managed, documents, revisions
    from .url_ingestion.schema import TABLES as urls, sources, generations
    from .ops.schema import snapshots

    tables = {datasets: {"SELECT"}, dataset_grants: {"SELECT"}}

    def require(table, *privileges):
        tables.setdefault(table, set()).update(privileges)

    if env.get("RAG_BACKEND_QUERY_DATASET_SOURCE") == "database":
        require(snapshots, "SELECT", "INSERT", "DELETE")
        require(datasets, "INSERT", "UPDATE")
        require(dataset_grants, "INSERT")
        require(dataset_grant_tokens, "SELECT", "INSERT", "UPDATE")
        # DatasetStore admission reads the graph-clear fence; deletion counts
        # all owned sources, including disabled ingestion families.
        require(documents, "SELECT")
        require(sources, "SELECT")
        # create_app exposes OpsService in database mode independently of the
        # ingestion/control flags. Graph clear locks these rows and writes its
        # ledger; graph reads also project managed revision identities.
        require(operations, "SELECT", "INSERT", "UPDATE")
        for table in (ingest_text_operations, revisions, generations):
            require(table, "SELECT", "UPDATE")
    for flag, group in (
        ("RAG_WORKFLOW_CONTROL_ENABLED", [operations]),
        (
            "RAG_BACKEND_TEXT_INGESTION_ENABLED",
            [ingest_text_operations, ingest_text_events],
        ),
        ("RAG_BACKEND_MANAGED_DOCUMENTS_ENABLED", managed),
        ("RAG_BACKEND_URL_INGESTION_ENABLED", urls),
    ):
        if enabled(env, flag):
            for table in group:
                require(table, "SELECT", "INSERT", "UPDATE")
    if enabled(env, "RAG_BACKEND_TEXT_INGESTION_ENABLED"):
        # Text events use a plain SELECT. Managed and URL event lookups use
        # SELECT FOR UPDATE and therefore retain UPDATE privilege.
        tables[ingest_text_events] = {"SELECT", "INSERT"}
    if enabled(env, "RAG_BACKEND_URL_INGESTION_ENABLED"):
        require(dataset_grants, "UPDATE")  # require_grant locks both join inputs.
    return tables


def verify_privileges(connection, env):
    if (
        connection.execute(
            sa.text("SELECT has_schema_privilege(current_user,'hawki_backend','USAGE')")
        ).scalar_one()
        is not True
    ):
        raise ValueError("Runtime schema usage unavailable")
    if (
        connection.execute(
            sa.text(
                "SELECT has_schema_privilege(current_user,'hawki_backend','CREATE')"
            )
        ).scalar_one()
        is not False
    ):
        raise ValueError("Runtime schema DDL privilege forbidden")
    for table, privileges in required_tables(env).items():
        for privilege in sorted(privileges):
            if (
                connection.execute(
                    sa.text(
                        "SELECT has_table_privilege(current_user,:table,:privilege)"
                    ),
                    {"table": table.fullname, "privilege": privilege},
                ).scalar_one()
                is not True
            ):
                raise ValueError("Required runtime table privilege unavailable")
        for column in table.columns:
            # These schemas currently use application-owned string identities.
            # Inspect serial/identity sequences when a numeric autoincrement is added.
            if (
                column.primary_key
                and isinstance(column.type, sa.Integer)
                and column.autoincrement is not False
            ):
                sequence = connection.execute(
                    sa.text("SELECT pg_get_serial_sequence(:table,:column)"),
                    {"table": table.fullname, "column": column.name},
                ).scalar_one()
                if sequence:
                    for privilege in ("USAGE", "SELECT"):
                        if (
                            connection.execute(
                                sa.text(
                                    "SELECT has_sequence_privilege(current_user,:sequence,:privilege)"
                                ),
                                {"sequence": sequence, "privilege": privilege},
                            ).scalar_one()
                            is not True
                        ):
                            raise ValueError("Runtime sequence privilege unavailable")
    functions = set()
    if any(
        enabled(env, flag)
        for flag in (
            "RAG_BACKEND_TEXT_INGESTION_ENABLED",
            "RAG_BACKEND_MANAGED_DOCUMENTS_ENABLED",
            "RAG_BACKEND_URL_INGESTION_ENABLED",
        )
    ):
        functions.add("pg_catalog.pg_advisory_xact_lock(bigint)")
    if any(
        enabled(env, flag)
        for flag in (
            "RAG_BACKEND_MANAGED_DOCUMENTS_ENABLED",
            "RAG_BACKEND_URL_INGESTION_ENABLED",
        )
    ):
        functions.update(
            {
                "pg_catalog.pg_try_advisory_lock(bigint)",
                "pg_catalog.pg_advisory_unlock(bigint)",
            }
        )
    for function in sorted(functions):
        if (
            connection.execute(
                sa.text(
                    "SELECT has_function_privilege(current_user,:function,'EXECUTE')"
                ),
                {"function": function},
            ).scalar_one()
            is not True
        ):
            raise ValueError("Runtime advisory-lock function unavailable")


def check_database(env: Mapping[str, str]) -> None:
    engine = build_engine(load_database_settings(env))
    try:
        with engine.connect() as connection, connection.begin():
            connection.execute(sa.text("SET TRANSACTION READ ONLY"))
            head = (
                connection.execute(
                    sa.text("SELECT version_num FROM hawki_backend.alembic_version")
                )
                .scalars()
                .all()
            )
            if head != [REQUIRED_REVISION]:
                raise ValueError("Schema revision is not current")
            if connection.execute(
                sa.text(
                    "SELECT rolsuper OR rolcreatedb OR rolcreaterole FROM pg_roles WHERE rolname=current_user"
                )
            ).scalar_one():
                raise ValueError("Runtime role is a superuser")
            if connection.execute(
                sa.text(
                    "SELECT EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','f') AND has_table_privilege(current_user,c.oid,'SELECT'))"
                )
            ).scalar_one():
                raise ValueError("Runtime role reads historical public tables")
            if (
                connection.execute(
                    sa.text(
                        "SELECT EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='hawki_backend' AND pg_has_role(current_user,c.relowner,'USAGE'))"
                    )
                ).scalar_one()
                is not False
            ):
                raise ValueError("Runtime owns schema objects")
            verify_privileges(connection, env)
    finally:
        engine.dispose()


def _http(
    env: Mapping[str, str], url: str, headers: dict[str, str] | None = None
) -> dict[str, Any]:
    with httpx.Client(timeout=3, follow_redirects=False, trust_env=False) as client:
        response = client.get(url, headers=headers or {})
        response.raise_for_status()
        return response.json()


def _control(env: Mapping[str, str]) -> None:
    raw = b"{}"
    stamp = str(int(time.time()))
    path = "/internal/workflow-control/health"
    signed = f"hawki-workflow-control-v1\nPOST\n{path}\n{stamp}\n".encode() + raw
    signature = hmac.new(
        env["RAG_WORKER_CALLBACK_SECRET"].encode(), signed, hashlib.sha256
    ).hexdigest()
    with httpx.Client(timeout=5, trust_env=False, follow_redirects=False) as client:
        response = client.post(
            env["RAG_WORKFLOW_CONTROL_URL"].rstrip("/") + path,
            content=raw,
            headers={
                "Content-Type": "application/json",
                "X-Hawki-Timestamp": stamp,
                "X-Hawki-Signature": "v1=" + signature,
            },
        )
        response.raise_for_status()
        if response.json() != {"status": "ok"}:
            raise ValueError("Workflow control is unavailable")


async def _temporal(env: Mapping[str, str]) -> None:
    from temporalio.client import Client
    from temporalio.api.workflowservice.v1 import DescribeNamespaceRequest

    from hawki_workflow_control.settings import load_settings

    settings = load_settings(env)
    client = await Client.connect(
        settings.temporal_address, namespace=settings.temporal_namespace
    )
    await client.workflow_service.describe_namespace(
        DescribeNamespaceRequest(namespace=client.namespace),
        timeout=timedelta(seconds=3),
    )


def _graph(env: Mapping[str, str]) -> None:
    from neo4j import GraphDatabase, Query, READ_ACCESS

    from hawki_graph_store.settings import load_neo4j_settings

    settings = load_neo4j_settings(env=env)
    with GraphDatabase.driver(
        settings.uri,
        auth=(settings.user, settings.password),
        connection_timeout=3,
        max_transaction_retry_time=0,
    ) as driver:
        with driver.session(
            database=settings.database, default_access_mode=READ_ACCESS
        ) as session:
            session.run(Query("RETURN 1 AS ready", timeout=3)).consume()


def _storage(env: Mapping[str, str]) -> None:
    root = Path(env.get("RAG_TEMPORAL_SHARED_ROOT", "/shared"))
    if (
        not root.is_absolute()
        or root == Path("/")
        or not root.is_dir()
        or not os.access(root, os.R_OK | os.W_OK | os.X_OK)
    ):
        raise ValueError("Shared storage is unavailable")
    if env.get("RAG_BACKEND_TEXT_INGESTION_SHARED_ROOT", str(root)) != str(root):
        raise ValueError("Shared roots differ")


def _crawler(env: Mapping[str, str]) -> None:
    from hawki_workflow_control.settings import load_settings
    from hawki_workflow_control.temporal_client import TemporalControlClient

    from .url_ingestion.crawler import ExternalCrawlerBoundary

    crawler = ExternalCrawlerBoundary(TemporalControlClient(load_settings(env)))
    asyncio.run(crawler.require_contract())


def _jwks(env: Mapping[str, str]) -> None:
    from .auth.jwks import JwksKeyProvider

    provider = JwksKeyProvider(load_auth_settings(env))
    try:
        name = "RAG_BACKEND_READINESS_JWKS_KEY_ID"
        if provider.get_key(env[name], "RS256") is None:
            raise ValueError("Configured public signing key unavailable")
    finally:
        provider.close()


def _models(env: Mapping[str, str]) -> None:
    models = json.loads(env["RAG_BACKEND_QUERY_MODELS"])
    if set(models) != {"ollama"}:
        raise ValueError("Model availability needs a reviewed provider-specific probe")
    from hawki_model_providers.ollama import ollama_api_url

    inventory = _http(env, ollama_api_url(env) + "/tags")
    available = {row["name"].removesuffix(":latest") for row in inventory["models"]}
    required = {
        value.removesuffix(":latest")
        for row in models.values()
        for value in row.values()
    }
    engine = build_engine(load_database_settings(env))
    try:
        with engine.connect() as connection, connection.begin():
            connection.execute(sa.text("SET TRANSACTION READ ONLY"))
            for provider, model in connection.execute(
                sa.text(
                    "SELECT embedding_provider,embedding_model FROM hawki_backend.datasets WHERE status='active'"
                )
            ):
                if provider != "ollama":
                    raise ValueError("Embedding provider needs an availability probe")
                required.add(model.removeprefix("ollama/").removesuffix(":latest"))
    finally:
        engine.dispose()
    if not required <= available:
        raise ValueError("Required models unavailable")


def qdrant_consumers(env: Mapping[str, str]) -> dict[str, Any]:
    """Resolve each actual client; distinct credentials are intentional, URLs are not."""
    from hawki_vector_store.settings import qdrant_settings_from_env
    from .datasets.catalog import QdrantCatalog
    from .text_ingestion.settings import (
        load_text_ingestion_settings,
        load_sink_settings,
    )

    consumers = {"query": qdrant_settings_from_env(env)}
    if env.get("RAG_BACKEND_QUERY_DATASET_SOURCE") == "database":
        consumers["dataset_catalog"] = QdrantCatalog.from_env(env)
        consumers["ops_catalog"] = QdrantCatalog.from_env(env)
    if enabled(env, "RAG_BACKEND_TEXT_INGESTION_ENABLED"):
        consumers["text_deletion"] = load_text_ingestion_settings(env)
    for flag, name in (
        ("RAG_BACKEND_MANAGED_DOCUMENTS_ENABLED", "managed_deletion"),
        ("RAG_BACKEND_URL_INGESTION_ENABLED", "url_deletion"),
    ):
        if enabled(env, flag):
            consumers[name] = load_sink_settings(env)
    destinations = {
        (
            settings.base_url
            if name == "query"
            else settings.base_url
            if name.endswith("catalog")
            else settings.qdrant_url
        ).rstrip("/")
        for name, settings in consumers.items()
    }
    if len(destinations) != 1:
        raise ValueError("Runtime Qdrant destinations conflict")
    return consumers


def _qdrant_inventory(env: Mapping[str, str], consumer: str) -> set[str]:
    from dataclasses import replace
    from hawki_vector_store.client import QdrantHTTP
    from .text_ingestion.vectors import vector_client

    settings = qdrant_consumers(env)[consumer]
    if consumer == "query":
        # Production requests transport retains its API key, TLS CA and proxy
        # semantics. Only the readiness retry/time budget is reduced.
        client = QdrantHTTP(
            settings=replace(
                settings,
                timeout=min(settings.timeout, 3),
                max_attempts=1,
                retry_attempts_by_operation={},
            )
        )
        try:
            return set(client.list_collections())
        finally:
            client.close()
    if consumer.endswith("catalog"):
        names = settings.available_collections()
        if names is None:
            raise ValueError("Runtime Qdrant catalog unavailable")
        return names
    with vector_client(settings) as client:
        response = client.get(
            settings.qdrant_url.rstrip("/") + "/collections", timeout=3
        )
        response.raise_for_status()
        return {row["name"] for row in response.json()["result"]["collections"]}


def _required_qdrant_collections(connection: sa.Connection) -> set[str]:
    """Require collections once any tenant-scoped ingestion history exists.

    Collections are created lazily by the indexer. Retained failed/deleted rows
    still count as history; lack of current documents is not proof of an unused
    dataset. Include URL requests that have not published a source yet.
    """
    from .persistence.schema import datasets
    from .managed_documents.schema import documents
    from .text_ingestion.schema import ingest_text_operations
    from .url_ingestion.schema import requests, sources

    history = sa.or_(
        *(
            sa.exists().where(
                table.c.tenant_id == datasets.c.tenant_id,
                table.c.dataset_id == datasets.c.dataset_id,
            )
            for table in (documents, ingest_text_operations, requests, sources)
        )
    )
    rows = connection.execute(
        sa.select(datasets.c.qdrant_collection, history.label("has_ingestion")).where(
            datasets.c.status == "active"
        )
    )
    required = set()
    for collection, has_ingestion in rows:
        # Missing canonical metadata is invalid even for an unused dataset.
        if not isinstance(collection, str) or not collection.strip():
            raise ValueError("Canonical collection unavailable")
        if has_ingestion:
            required.add(collection)
    return required


def _qdrant(env: Mapping[str, str], consumer: str = "query") -> None:
    names = _qdrant_inventory(env, consumer)
    engine = build_engine(load_database_settings(env))
    try:
        with engine.connect() as connection, connection.begin():
            connection.execute(sa.text("SET TRANSACTION READ ONLY"))
            required = _required_qdrant_collections(connection)
    finally:
        engine.dispose()
    if not required <= names:
        raise ValueError("Canonical collection unavailable")


def graph_required(env: Mapping[str, str]) -> bool:
    """Derive graph use from capabilities and the canonical owned registry."""
    # These capabilities can dispatch graph writers or clean historical graph
    # contributions even when current datasets have graph retrieval disabled.
    if any(
        enabled(env, flag)
        for flag in (
            "RAG_WORKFLOW_CONTROL_ENABLED",
            "RAG_BACKEND_MANAGED_DOCUMENTS_ENABLED",
            "RAG_BACKEND_URL_INGESTION_ENABLED",
        )
    ):
        return True
    engine = build_engine(load_database_settings(env))
    try:
        with engine.connect() as connection, connection.begin():
            connection.execute(sa.text("SET TRANSACTION READ ONLY"))
            rows = connection.execute(
                sa.text(
                    "SELECT graph_enabled,neo4j_namespace FROM hawki_backend.datasets WHERE status='active'"
                )
            )
            required = False
            for graph, namespace in rows:
                if (
                    type(graph) is not bool
                    or graph
                    and (not isinstance(namespace, str) or not namespace.strip())
                ):
                    raise ValueError("Canonical dataset graph scope unavailable")
                required |= graph
            return required
    finally:
        engine.dispose()


def _reranker(env):
    mode = env.get("RAG_BACKEND_QUERY_RERANKER", "none").lower().strip()
    if mode in {"none", "cosine"}:
        return
    if mode != "external":
        raise ValueError("unsupported_reranker_availability_probe")
    import requests
    from hawki_rag_retrieval.adapters.external_reranker import (
        load_external_reranker_settings,
    )
    from hawki_rag_contracts.retrieval.rerank import RerankResponse

    settings = load_external_reranker_settings(env)
    if not settings.model:
        raise ValueError("External reranker model must be explicit")
    # Deterministic inference only: no user data, callbacks, jobs or persistence.
    documents = ["RAWKI readiness document."]
    response = requests.post(
        settings.url,
        headers=settings.headers,
        json=settings.body("RAWKI readiness", documents),
        timeout=(3, 5),
        allow_redirects=False,
    )
    if not 200 <= response.status_code < 300:
        raise ValueError("External reranker requires a direct 2xx response")
    response.raise_for_status()
    result = RerankResponse.model_validate(response.json())
    if (
        result.model != settings.model
        or len(result.results) != 1
        or result.results[0].index != 0
        or result.results[0].document != documents[0]
    ):
        raise ValueError("External reranker response contract incompatible")


def live_checks(env: Mapping[str, str]) -> dict[str, str]:
    probes = {
        "database_schema_role": lambda: check_database(env),
        "jwks": lambda: _jwks(env),
        "query_models": lambda: _models(env),
        "query_reranker": lambda: _reranker(env),
    }
    configuration_results = {}
    try:
        for consumer in qdrant_consumers(env):
            probes["qdrant." + consumer] = lambda name=consumer: _qdrant(env, name)
    except Exception:
        configuration_results["qdrant.configuration"] = "FAIL"
    try:
        if graph_required(env):
            probes["neo4j"] = lambda: _graph(env)
        configuration_results["graph_requirement"] = "PASS"
    except Exception:
        configuration_results["graph_requirement"] = "FAIL"
    ingestion = any(
        enabled(env, name)
        for name in (
            "RAG_BACKEND_TEXT_INGESTION_ENABLED",
            "RAG_BACKEND_MANAGED_DOCUMENTS_ENABLED",
            "RAG_BACKEND_URL_INGESTION_ENABLED",
        )
    )
    if ingestion or enabled(env, "RAG_WORKFLOW_CONTROL_ENABLED"):
        probes.update(
            workflow_control_relay=lambda: _control(env),
            temporal=lambda: asyncio.run(asyncio.wait_for(_temporal(env), 5)),
        )
    if ingestion:
        probes["shared_storage_permissions"] = lambda: _storage(env)
    if enabled(env, "RAG_BACKEND_URL_INGESTION_ENABLED"):
        probes["crawler_contract"] = lambda: _crawler(env)
    result = configuration_results
    for name, probe in probes.items():
        try:
            probe()
            result[name] = "PASS"
        except Exception:
            result[name] = "FAIL"  # Never print driver/HTTP exceptions or credentials.
    return result


def report(env: Mapping[str, str], live: bool = False) -> dict[str, Any]:
    checks = configuration_checks(env)
    if live:
        checks.update(live_checks(env))
    else:
        checks["runtime_dependencies"] = "NOT EXECUTED"
    return {
        "status": "PASS"
        if all(value == "PASS" for value in checks.values())
        else "FAIL",
        "read_only": True,
        "required_revision": REQUIRED_REVISION,
        "checks": checks,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--live",
        action="store_true",
        help="Perform bounded read-only dependency probes",
    )
    args = parser.parse_args()
    result = report(os.environ, live=args.live)
    print(json.dumps(result, sort_keys=True))
    return 0 if result["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
