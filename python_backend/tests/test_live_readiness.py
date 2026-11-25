"""Adversarial consumer, privilege and graph readiness scenarios; no live stores."""

from types import SimpleNamespace
from unittest.mock import MagicMock

import httpx
import pytest
import requests

from hawki_backend import readiness
from hawki_backend.deployment import ENABLE_FLAGS
from test_readiness import environment


@pytest.fixture
def qdrant(monkeypatch):
    env = environment() | {
        "QDRANT_API_KEY": "query-key",
        "RAG_BACKEND_TEXT_INGESTION_QDRANT_API_KEY": "text-key",
    }
    engine = MagicMock()
    engine.connect.return_value.__enter__.return_value.execute.return_value.scalars.return_value = []
    monkeypatch.setattr(readiness, "build_engine", lambda _: engine)
    observed = []
    rejected = set()

    def response(url, headers):
        key = headers.get("api-key", "")
        observed.append((str(url), key))
        return httpx.Response(
            401 if key in rejected or "broken" in str(url) else 200,
            json={"result": {"collections": []}},
        )

    original = httpx.Client
    monkeypatch.setattr(
        httpx,
        "Client",
        lambda **kw: original(
            transport=httpx.MockTransport(lambda r: response(r.url, r.headers)), **kw
        ),
    )

    def query_request(self, method, url, **kw):
        r = response(url, kw["headers"])
        r.request = httpx.Request(method, url)
        return r

    monkeypatch.setattr(requests.Session, "request", query_request)
    return SimpleNamespace(env=env, observed=observed, rejected=rejected)


@pytest.mark.parametrize("enabled", [False, True])
def test_distinct_bad_text_credential_is_required_only_when_used(qdrant, enabled):
    qdrant.env["RAG_BACKEND_TEXT_INGESTION_ENABLED"] = str(enabled).lower()
    qdrant.rejected.add("text-key")
    results = {}
    for name in readiness.qdrant_consumers(qdrant.env):
        try:
            readiness._qdrant(qdrant.env, name)
            results[name] = True
        except httpx.HTTPError:
            results[name] = False
    assert results["query"]
    assert results.get("text_deletion") == (False if enabled else None)
    assert ("text-key" in [key for _, key in qdrant.observed]) == enabled


@pytest.mark.parametrize("text_enabled", [False, True])
def test_bad_text_credential_controls_overall_readiness(
    qdrant, monkeypatch, text_enabled
):
    qdrant.env["RAG_BACKEND_TEXT_INGESTION_ENABLED"] = str(text_enabled).lower()
    qdrant.rejected.add("text-key")
    for name in (
        "check_database",
        "_graph",
        "_jwks",
        "_models",
        "_reranker",
        "_control",
        "_storage",
        "_crawler",
    ):
        monkeypatch.setattr(readiness, name, lambda *_: None)

    async def temporal(_):
        return None

    monkeypatch.setattr(readiness, "_temporal", temporal)
    report = readiness.report(qdrant.env, live=True)
    assert report["checks"]["qdrant.query"] == "PASS"
    assert report["checks"].get("qdrant.text_deletion") == (
        "FAIL" if text_enabled else None
    )
    assert report["status"] == ("FAIL" if text_enabled else "PASS")


def test_different_valid_qdrant_credentials_probe_every_consumer(qdrant):
    for name in readiness.qdrant_consumers(qdrant.env):
        readiness._qdrant(qdrant.env, name)
    assert {key for _, key in qdrant.observed} == {"query-key", "text-key"}
    assert set(readiness.qdrant_consumers(qdrant.env)) == {
        "query",
        "dataset_catalog",
        "ops_catalog",
        "text_deletion",
        "managed_deletion",
        "url_deletion",
    }


def test_text_on_uses_the_actual_runtime_feature_parser(qdrant):
    qdrant.env["RAG_BACKEND_TEXT_INGESTION_ENABLED"] = " on "
    assert "text_deletion" in readiness.qdrant_consumers(qdrant.env)


@pytest.mark.parametrize("fault", ["destination", "credential"])
def test_dataset_catalog_remains_required_without_ingestion(qdrant, fault):
    env = qdrant.env | dict.fromkeys(ENABLE_FLAGS, "false")
    if fault == "destination":
        env["QDRANT_HTTP_URL"] = "http://broken:6333"
        with pytest.raises(ValueError, match="conflict"):
            readiness.qdrant_consumers(env)
        assert qdrant.observed == []
    else:
        qdrant.rejected.add("query-key")
        with pytest.raises(ValueError, match="catalog"):
            readiness._qdrant(env, "dataset_catalog")


def test_broken_effective_store_never_uses_healthy_alternate(qdrant):
    env = qdrant.env | {
        "QDRANT_HOST": "broken",
        "QDRANT_HTTP_URL": "http://broken:6333",
        "RAG_BACKEND_TEXT_INGESTION_QDRANT_URL": "http://broken:6333",
    }
    with pytest.raises(httpx.HTTPError):
        readiness._qdrant(env)
    assert qdrant.observed == [("http://broken:6333/collections", "query-key")]


@pytest.mark.parametrize(
    "table", ["dataset_grants", "managed_document_events", "url_ingestion_events"]
)
def test_locking_tables_require_update(table):
    connection = MagicMock()

    def execute(statement, params=None):
        sql = str(statement)
        assert sql.startswith("SELECT")
        allowed = "'CREATE'" not in sql
        if (
            params
            and params.get("table") == "hawki_backend." + table
            and params.get("privilege") == "UPDATE"
        ):
            allowed = False
        return SimpleNamespace(scalar_one=lambda: allowed)

    connection.execute.side_effect = execute
    with pytest.raises(ValueError, match="privilege"):
        readiness.verify_privileges(connection, environment())


def test_retained_database_authority_dependencies_without_ingestion():
    env = environment() | dict.fromkeys(ENABLE_FLAGS, "false")
    tables = {t.name: p for t, p in readiness.required_tables(env).items()}
    assert tables["workflow_operations"] == {"SELECT", "INSERT", "UPDATE"}
    assert tables["managed_documents"] == {"SELECT"}
    assert tables["url_ingestion_sources"] == {"SELECT"}
    for table in (
        "managed_document_revisions",
        "url_ingestion_generations",
        "ingest_text_operations",
    ):
        assert tables[table] == {"SELECT", "UPDATE"}
    assert "managed_document_mutations" not in tables
    assert "url_ingestion_requests" not in tables
    assert "ingest_text_events" not in tables


@pytest.mark.parametrize(
    "scope,required",
    [
        ([(False, None)], False),
        ([(True, "scope")], True),
        ([], False),
        ([(None, None)], None),
        ([(True, None)], None),
    ],
)
def test_graph_dependency_uses_canonical_dataset_scopes(monkeypatch, scope, required):
    env = (
        environment()
        | dict.fromkeys(ENABLE_FLAGS, "false")
        | {"GRAPH_OPS_BACKEND": "laravel"}
    )
    engine = MagicMock()
    engine.connect.return_value.__enter__.return_value.execute.return_value = scope
    monkeypatch.setattr(readiness, "build_engine", lambda _: engine)
    if required is None:
        with pytest.raises(ValueError, match="scope"):
            readiness.graph_required(env)
    else:
        assert readiness.graph_required(env) is required


@pytest.mark.parametrize(
    "capability",
    [
        "RAG_WORKFLOW_CONTROL_ENABLED",
        "RAG_BACKEND_MANAGED_DOCUMENTS_ENABLED",
        "RAG_BACKEND_URL_INGESTION_ENABLED",
    ],
)
def test_graph_capabilities_keep_neo4j_required(capability):
    env = environment() | dict.fromkeys(ENABLE_FLAGS, "false") | {capability: "true"}
    assert readiness.graph_required(env)


@pytest.mark.parametrize("graph", [False, True, None])
def test_vector_only_runtime_omits_down_neo4j_but_unknown_scope_fails(
    monkeypatch, graph
):
    env = (
        environment()
        | dict.fromkeys(ENABLE_FLAGS, "false")
        | {"GRAPH_OPS_BACKEND": "laravel"}
    )
    engine = MagicMock()
    engine.connect.return_value.__enter__.return_value.execute.return_value = [
        (graph, "scope" if graph else None)
    ]
    monkeypatch.setattr(readiness, "build_engine", lambda _: engine)
    for name in ("check_database", "_qdrant", "_jwks", "_models", "_reranker"):
        monkeypatch.setattr(readiness, name, lambda *_: None)
    monkeypatch.setattr(
        readiness, "_graph", lambda _: (_ for _ in ()).throw(ConnectionError())
    )
    result = readiness.report(env, live=True)
    assert result["status"] == ("PASS" if graph is False else "FAIL")
    assert ("neo4j" in result["checks"]) == (graph is True)


def test_cutover_keeps_graph_dependency_when_neo4j_is_down(monkeypatch):
    env = environment()
    for name in (
        "check_database",
        "_qdrant",
        "_jwks",
        "_models",
        "_reranker",
        "_control",
        "_storage",
        "_crawler",
    ):
        monkeypatch.setattr(readiness, name, lambda *_: None)

    async def temporal(_):
        return None

    monkeypatch.setattr(readiness, "_temporal", temporal)
    monkeypatch.setattr(
        readiness, "_graph", lambda _: (_ for _ in ()).throw(ConnectionError())
    )
    result = readiness.report(env, live=True)
    assert result["status"] == "FAIL"
    assert result["checks"]["neo4j"] == "FAIL"


@pytest.mark.parametrize(
    "fault",
    [
        "valid",
        "down",
        "auth",
        "missing_model",
        "wrong_model",
        "malformed",
        "dialect",
        "endpoint",
    ],
)
def test_external_reranker_uses_runtime_endpoint_auth_model(monkeypatch, fault):
    env = environment() | {
        "RAG_BACKEND_QUERY_RERANKER": "external",
        "RERANKER_API_URL": "http://reranker.test/v1/rerank",
        "RERANKER_API_KEY": "fixture-key",
        "RERANKER_MODEL": "reviewed-model",
    }
    if fault == "missing_model":
        del env["RERANKER_MODEL"]
    if fault == "dialect":
        env["RERANKER_API_DIALECT"] = "unknown"
    if fault == "endpoint":
        env["RERANKER_API_URL"] = "http://wrong.test/v1/rerank"

    def post(url, *, headers, json, timeout, allow_redirects):
        if fault in {"dialect", "missing_model"}:
            pytest.fail("Invalid configuration must refuse before probing")
        assert headers["Authorization"] == "Bearer fixture-key"
        assert json["model"] == "reviewed-model"
        assert timeout == (3, 5)
        assert allow_redirects is False
        if fault == "down":
            raise requests.ConnectionError()
        status = (
            401 if fault == "auth" else 404 if url.startswith("http://wrong") else 200
        )
        payload = {
            "model": "wrong" if fault == "wrong_model" else "reviewed-model",
            "results": [
                {"index": 0, "document": json["documents"][0], "relevance_score": 0.9}
            ],
        }
        if fault == "malformed":
            payload["results"][0]["relevance_score"] = "nan"
        r = httpx.Response(status, json=payload, request=httpx.Request("POST", url))
        return r

    monkeypatch.setattr(requests, "post", post)
    if fault == "valid":
        readiness._reranker(env)
    else:
        with pytest.raises((ValueError, httpx.HTTPError, requests.RequestException)):
            readiness._reranker(env)


@pytest.mark.parametrize("fault", ["valid", "key", "model"])
def test_probe_against_actual_local_reranker_http_contract(monkeypatch, fault):
    from pathlib import Path
    from fastapi.testclient import TestClient

    monkeypatch.syspath_prepend(
        str(
            Path(__file__).resolve().parents[2]
            / "python_rag/services/hawki_reranker/src"
        )
    )
    from hawki_reranker.main import create_app
    from hawki_reranker.settings import RerankerSettings

    class Model:
        def predict(self, pairs):
            return [0.9] * len(pairs)

    service = create_app(
        settings=RerankerSettings(model_name="reviewed", api_key="secret"),
        model_factory=lambda _: Model(),
    )
    with TestClient(service) as client:
        monkeypatch.setattr(
            requests,
            "post",
            lambda url, **kw: client.post(
                "/v1/rerank", headers=kw["headers"], json=kw["json"]
            ),
        )
        env = {
            "RAG_BACKEND_QUERY_RERANKER": "external",
            "RERANKER_API_URL": "http://local.test/v1/rerank",
            "RERANKER_API_KEY": "wrong" if fault == "key" else "secret",
            "RERANKER_MODEL": "missing" if fault == "model" else "reviewed",
        }
        if fault == "valid":
            readiness._reranker(env)
        else:
            with pytest.raises((httpx.HTTPError, ValueError)):
                readiness._reranker(env)
