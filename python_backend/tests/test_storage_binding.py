"""Storage identity and real dispatch regression tests; no deployed services."""

import io
import json
import os
import sys
from pathlib import Path
from types import SimpleNamespace

import pytest
import requests

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import query_readiness as readiness  # noqa: E402
import query_storage as storage  # noqa: E402


@pytest.fixture
def deployed(monkeypatch):
    for name in os.environ:
        if name.startswith(("QDRANT_", "NEO4J_", "HAWKI_PARITY_")):
            monkeypatch.delenv(name)
    values = {
        "QDRANT_SCHEME": "http",
        "QDRANT_HOST": "runtime-qdrant",
        "QDRANT_PORT": "6333",
        "QDRANT_API_KEY": "fixture-api-key",
        "QDRANT_TIMEOUT": "17",
        "NEO4J_URI": "bolt://runtime-neo4j:7687",
        "NEO4J_USERNAME": "fixture-user",
        "NEO4J_PASSWORD": "fixture-password",
        "NEO4J_DATABASE": "hawki",
    }
    for name, value in values.items():
        monkeypatch.setenv(name, value)
    return values


@pytest.mark.parametrize(
    "confirmations",
    [
        {},
        {"HAWKI_PARITY_QDRANT_URL": "http://runtime-qdrant:6333"},
        {"HAWKI_PARITY_QDRANT_URL": "http://RUNTIME-QDRANT:6333/"},
        {
            "HAWKI_PARITY_NEO4J_URI": "bolt://RUNTIME-NEO4J/",
            "HAWKI_PARITY_NEO4J_DATABASE": "hawki",
        },
        {
            "HAWKI_PARITY_QDRANT_API_KEY": "fixture-api-key",
            "HAWKI_PARITY_NEO4J_AUTH": "fixture-user:fixture-password",
        },
    ],
)
def test_authoritative_and_equivalent_confirmations(deployed, confirmations):
    vector, graph = storage.effective_storage(confirmations)
    assert vector.base_url == "http://runtime-qdrant:6333"
    assert vector.timeout == 17
    assert graph.uri == "bolt://runtime-neo4j:7687"
    assert graph.database == "hawki"


@pytest.mark.parametrize(
    "name,value",
    [
        ("HAWKI_PARITY_QDRANT_URL", "http://unrelated:6333"),
        ("HAWKI_PARITY_QDRANT_URL", "http://runtime-qdrant:6334"),
        ("HAWKI_PARITY_QDRANT_URL", "https://runtime-qdrant:6333"),
        ("HAWKI_PARITY_QDRANT_URL", "http://runtime-qdrant:6333/base"),
        ("HAWKI_PARITY_QDRANT_URL", "http://alias-qdrant:6333"),
        ("HAWKI_PARITY_QDRANT_URL", "http://user:password@runtime-qdrant:6333"),
        ("HAWKI_PARITY_QDRANT_URL", "http://runtime-qdrant:6333?"),
        ("HAWKI_PARITY_NEO4J_URI", "bolt://unrelated:7687"),
        ("HAWKI_PARITY_NEO4J_URI", "bolt://runtime-neo4j:7688"),
        ("HAWKI_PARITY_NEO4J_URI", "neo4j://runtime-neo4j:7687"),
        ("HAWKI_PARITY_NEO4J_URI", "bolt+s://runtime-neo4j:7687"),
        ("HAWKI_PARITY_NEO4J_URI", "bolt://runtime-neo4j:7687/base"),
        ("HAWKI_PARITY_NEO4J_URL", "http://unrelated:7474"),
        ("HAWKI_PARITY_NEO4J_DATABASE", "neo4j"),
        ("HAWKI_PARITY_NEO4J_AUTH", "other:fixture-password"),
        ("HAWKI_PARITY_QDRANT_API_KEY", "unrelated-key"),
    ],
)
def test_conflicting_operator_storage_rejected(deployed, name, value):
    with pytest.raises(ValueError):
        storage.effective_storage({name: value})


@pytest.mark.parametrize(
    "name",
    [
        "QDRANT_SCHEME",
        "QDRANT_HOST",
        "QDRANT_PORT",
        "NEO4J_URI",
        "NEO4J_USERNAME",
        "NEO4J_PASSWORD",
        "NEO4J_DATABASE",
    ],
)
def test_missing_authoritative_identity_fails(deployed, monkeypatch, name):
    monkeypatch.delenv(name)
    with pytest.raises(ValueError):
        storage.effective_storage({})


@pytest.mark.parametrize("database", ["hawki", "other-db", "neo4j"])
def test_actual_dispatch_uses_runtime_uri_database_and_credentials(
    deployed, monkeypatch, database
):
    monkeypatch.setenv("NEO4J_DATABASE", database)
    dispatched = []
    calls = []

    def respond(self, request, **kwargs):
        dispatched.append((request, kwargs))
        response = requests.Response()
        response.request = request
        response.status_code = 200
        response._content = b'{"result":{"points":[{"id":"present"}]}}'
        return response

    monkeypatch.setattr(requests.adapters.HTTPAdapter, "send", respond)

    class Driver:
        def __enter__(self):
            return self

        def __exit__(self, *args):
            pass

        def session(self, **kwargs):
            calls.append(("session", kwargs))
            return self

        def run(self, query, **kwargs):
            calls.append(("query", query.text, kwargs))
            return SimpleNamespace(single=lambda **kwargs: {"count": 1})

    def driver(uri, **kwargs):
        calls.append(("driver", uri, kwargs))
        return Driver()

    monkeypatch.setattr(storage.GraphDatabase, "driver", driver)
    storage.check_inventory(
        [
            {
                "dataset_id": "third",
                "qdrant_collection": "third-collection",
                "neo4j_namespace": "third-namespace",
                "graph_enabled": True,
            }
        ],
        {},
    )
    assert [request.url for request, _ in dispatched] == [
        "http://runtime-qdrant:6333/collections/third-collection",
        "http://runtime-qdrant:6333/collections/third-collection/points/scroll",
    ]
    assert all(
        request.headers["api-key"] == "fixture-api-key" for request, _ in dispatched
    )
    assert all(options["timeout"] == 17 for _, options in dispatched)
    assert (
        json.loads(dispatched[1][0].body)["filter"]["must"][0]["match"]["value"]
        == "third"
    )
    assert calls[0] == (
        "driver",
        "bolt://runtime-neo4j:7687",
        {
            "auth": ("fixture-user", "fixture-password"),
            "max_transaction_retry_time": 30,
        },
    )
    assert calls[1] == (
        "session",
        {"database": database, "default_access_mode": storage.READ_ACCESS},
    )
    assert calls[2][2] == {"dataset_id": "third", "namespace": "third-namespace"}


def test_redirect_does_not_select_another_storage_service(deployed, monkeypatch):
    dispatched = []

    def respond(self, request, **kwargs):
        dispatched.append(request.url)
        response = requests.Response()
        response.request = request
        response.status_code = 307
        response.headers["Location"] = "http://unrelated:6333"
        response._content = b""
        return response

    monkeypatch.setattr(requests.adapters.HTTPAdapter, "send", respond)
    with pytest.raises(ValueError, match="Collection unavailable"):
        storage.check_inventory([{"qdrant_collection": "third"}], {})
    assert dispatched == ["http://runtime-qdrant:6333/collections/third"]


def test_container_probe_uses_bound_id_and_stdin_not_local_settings(monkeypatch):
    calls = []
    monkeypatch.setenv("QDRANT_HOST", "unrelated-operator-shell")
    monkeypatch.setenv("HAWKI_PARITY_QDRANT_API_KEY", "confirmation-secret")

    def run(command, **kwargs):
        calls.append((command, kwargs))
        return SimpleNamespace(returncode=0, stdout='{"status":"PASS"}')

    monkeypatch.setattr(readiness.subprocess, "run", run)
    assert readiness.probe_storage("bff-bound-id", [{"dataset_id": "third"}])
    command, options = calls[0]
    assert command[:7] == [
        "docker",
        "exec",
        "-i",
        "bff-bound-id",
        "python",
        "-c",
        Path(storage.__file__).read_text(),
    ]
    assert "confirmation-secret" not in " ".join(command)
    request = json.loads(options["input"])
    assert request["inventory"] == [{"dataset_id": "third"}]
    assert (
        request["confirmations"]["HAWKI_PARITY_QDRANT_API_KEY"] == "confirmation-secret"
    )
    assert "unrelated-operator-shell" not in options["input"]


@pytest.mark.parametrize("optimize", [0, 1, 2])
@pytest.mark.parametrize(
    "name,value",
    [
        ("HAWKI_PARITY_NEO4J_DATABASE", "wrong"),
        ("HAWKI_PARITY_NEO4J_URI", "bolt://unrelated:7687"),
        ("HAWKI_PARITY_QDRANT_URL", "http://unrelated:6333"),
    ],
)
def test_optimized_container_program_rejects_conflict_safely(
    deployed, monkeypatch, capsys, optimize, name, value
):
    request = {"inventory": None, "confirmations": {name: value}}
    monkeypatch.setattr(sys, "stdin", io.StringIO(json.dumps(request)))
    with pytest.raises(SystemExit) as error:
        exec(
            compile(
                Path(storage.__file__).read_text(),
                "<storage-probe>",
                "exec",
                optimize=optimize,
            ),
            {"__name__": "__main__"},
        )
    assert error.value.code == 1
    assert json.loads(capsys.readouterr().out) == {"status": "FAIL"}


def test_live_inventory_requires_dispatched_probe_success(monkeypatch):
    from parity import test_live_scope_integration as live

    monkeypatch.setattr(live, "_configured_datasets", lambda: [{"dataset_id": "third"}])
    monkeypatch.setattr(
        live, "_bound_target", lambda: ("bound-id", "http://hawki_backend/v1/query")
    )
    calls = []

    def probe(backend_id, inventory):
        calls.append((backend_id, inventory))
        return False

    monkeypatch.setattr(live, "probe_storage", probe)
    with pytest.raises(pytest.fail.Exception, match="Bound deployed storage"):
        live.test_inventory_targets_exist_in_live_storage()
    assert calls == [("bound-id", [{"dataset_id": "third"}])]
