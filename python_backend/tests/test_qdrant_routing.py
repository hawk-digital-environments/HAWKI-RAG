"""H6 regressions at Requests' real connection boundary; no network access."""

import io
import json
import os
import sys
from contextlib import redirect_stdout
from pathlib import Path
from types import SimpleNamespace

import httpx
import pytest
import requests
from urllib3.util import connection

from hawki_vector_store.requests import QdrantRequest
from hawki_vector_store.transport import QdrantHTTPTransport

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import query_readiness as readiness  # noqa: E402
import query_storage as storage  # noqa: E402


DEPLOYED = {
    "QDRANT_SCHEME": "http",
    "QDRANT_HOST": "10.1.2.3",
    "QDRANT_PORT": "6333",
    "QDRANT_API_KEY": "fixture-api-key",
    "QDRANT_TIMEOUT": "17",
    "NEO4J_URI": "bolt://runtime-graph:7687",
    "NEO4J_USER": "fixture-user",
    "NEO4J_PASSWORD": "fixture-password",
    "NEO4J_DATABASE": "hawki",
}
INVENTORY = [{"dataset_id": "a", "qdrant_collection": "qa", "graph_enabled": False}]


class InventorySocket:
    """Feed valid inventory responses after urllib3 selects its connection host."""

    def settimeout(self, value):
        pass

    def sendall(self, data):
        pass

    def makefile(self, *args, **kwargs):
        body = b'{"result":{"points":[{"id":"present"}]}}'
        return io.BytesIO(
            b"HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: "
            + str(len(body)).encode()
            + b"\r\n\r\n"
            + body
        )

    def close(self):
        pass


@pytest.fixture
def connections(monkeypatch):
    targets = []

    def connect(address, *args, **kwargs):
        targets.append(address)
        return InventorySocket()

    # Keep Session, HTTPAdapter, proxy managers and urllib3 connection pools real.
    # Only replace socket creation, after direct/proxy routing has been selected.
    monkeypatch.setattr(connection, "create_connection", connect)
    return targets


def test_exact_h6_reproduction(monkeypatch, connections):
    monkeypatch.setattr(
        os,
        "environ",
        {
            **DEPLOYED,
            "HTTP_PROXY": "http://unrelated-proxy:8888",
            "NO_PROXY": "10.0.0.0/8",
        },
    )
    url = "http://10.1.2.3:6333/collections/qa"
    with httpx.Client() as old_inventory:
        pool = old_inventory._transport_for_url(httpx.URL(url))._pool
        assert type(pool).__name__ == "HTTPProxy"
        assert pool._proxy_url.host == b"unrelated-proxy"
        assert pool._proxy_url.port == 8888
    storage.check_inventory(INVENTORY, {})
    assert connections == [("10.1.2.3", 6333)] * 2


@pytest.mark.parametrize(
    "routing,target",
    [
        ({}, ("10.1.2.3", 6333)),
        ({"HTTP_PROXY": "http://proxy-a:8888"}, ("proxy-a", 8888)),
        ({"http_proxy": "http://proxy-a:8888"}, ("proxy-a", 8888)),
        ({"ALL_PROXY": "http://proxy-all:8888"}, ("proxy-all", 8888)),
        ({"all_proxy": "http://proxy-all:8888"}, ("proxy-all", 8888)),
        (
            {"HTTP_PROXY": "http://proxy-a:8888", "NO_PROXY": "10.0.0.0/8"},
            ("10.1.2.3", 6333),
        ),
        (
            {"HTTP_PROXY": "http://proxy-a:8888", "no_proxy": "10.0.0.0/8"},
            ("10.1.2.3", 6333),
        ),
        (
            {"HTTP_PROXY": "http://proxy-a:8888", "NO_PROXY": "192.168.0.0/16"},
            ("proxy-a", 8888),
        ),
        (
            {"HTTP_PROXY": "http://ignored:8888", "http_proxy": "http://lower:8888"},
            ("lower", 8888),
        ),
        ({"HTTP_PROXY": "http://ignored:8888", "http_proxy": ""}, ("10.1.2.3", 6333)),
        (
            {"HTTP_PROXY": "http://proxy-a:8888", "REQUEST_METHOD": "GET"},
            ("10.1.2.3", 6333),
        ),
        (
            {
                "HTTP_PROXY": "http://proxy-a:8888",
                "NO_PROXY": "10.0.0.0/8",
                "no_proxy": "192.168.0.0/16",
            },
            ("proxy-a", 8888),
        ),
        (
            {"HTTP_PROXY": "http://proxy-a:8888", "ALL_PROXY": "http://ignored:8888"},
            ("proxy-a", 8888),
        ),
    ],
)
def test_inventory_connection_target_matches_production_transport(
    monkeypatch, connections, routing, target
):
    monkeypatch.setattr(os, "environ", {**DEPLOYED, **routing})
    storage.check_inventory(INVENTORY, {})
    assert connections == [target, target]
    vector = storage.qdrant_settings_from_env()
    with requests.Session() as session:
        retrieval = QdrantHTTPTransport(
            base_url=vector.base_url,
            api_key=vector.api_key,
            default_timeout=vector.timeout,
            session=session,
        )
        response = retrieval.send(QdrantRequest("GET", "/collections/qa"))
    assert response.status_code == 200
    assert connections == [target, target, target]
    expected = (
        {}
        if target == ("10.1.2.3", 6333)
        else {"HTTP_PROXY": f"http://{target[0]}:{target[1]}"}
    )
    assert storage.qdrant_route_digest() == route_for(monkeypatch, expected)


def route_for(monkeypatch, env):
    with monkeypatch.context() as context:
        context.setattr(os, "environ", {**DEPLOYED, **env})
        return storage.qdrant_route_digest()


@pytest.mark.parametrize(
    "backend,bridge,equal",
    [
        ({}, {}, True),
        ({"HTTP_PROXY": "http://a:8888"}, {"HTTP_PROXY": "http://b:8888"}, False),
        ({}, {"HTTP_PROXY": "http://a:8888"}, False),
        (
            {"HTTP_PROXY": "http://a:8888", "NO_PROXY": "10.0.0.0/8"},
            {"HTTP_PROXY": "http://a:8888"},
            False,
        ),
        ({"HTTP_PROXY": "http://a:8888"}, {"http_proxy": "http://a:8888"}, True),
        ({"HTTP_PROXY": "http://A"}, {"http_proxy": "http://a:80"}, True),
        (
            {"HTTP_PROXY": "http://:ignored-password@a:8888"},
            {"HTTP_PROXY": "http://a:8888"},
            True,
        ),
        (
            {"HTTP_PROXY": "http://ignored:8888", "http_proxy": "http://a:8888"},
            {"http_proxy": "http://a:8888"},
            True,
        ),
        ({"HTTP_PROXY": "http://a:8888", "NO_PROXY": "10.0.0.0/8"}, {}, True),
        (
            {"HTTP_PROXY": "http://a:8888", "NO_PROXY": "10.0.0.0/8"},
            {"HTTP_PROXY": "http://b:8888", "NO_PROXY": "10.1.2.3"},
            True,
        ),
        (
            {"HTTP_PROXY": "http://user:secret-a@a:8888"},
            {"HTTP_PROXY": "http://user:secret-b@a:8888"},
            False,
        ),
        ({"HTTPS_PROXY": "http://ignored:8888"}, {}, True),
        (
            {"QDRANT_SCHEME": "https", "HTTPS_PROXY": "http://a:8888"},
            {"QDRANT_SCHEME": "https", "https_proxy": "http://a:8888"},
            True,
        ),
        (
            {
                "QDRANT_SCHEME": "https",
                "HTTPS_PROXY": "http://ignored:8888",
                "https_proxy": "http://a:8888",
            },
            {"QDRANT_SCHEME": "https", "HTTPS_PROXY": "http://a:8888"},
            True,
        ),
        (
            {"QDRANT_SCHEME": "https", "HTTPS_PROXY": "http://a:8888"},
            {"QDRANT_SCHEME": "https", "HTTPS_PROXY": "http://b:8888"},
            False,
        ),
        (
            {"ALL_PROXY": "http://ignored:8888", "all_proxy": "http://a:8888"},
            {"ALL_PROXY": "http://a:8888"},
            True,
        ),
    ],
)
def test_effective_routes_follow_requests_precedence(
    monkeypatch, backend, bridge, equal
):
    assert (route_for(monkeypatch, backend) == route_for(monkeypatch, bridge)) is equal


@pytest.mark.parametrize("optimize", [0, 1, 2])
@pytest.mark.parametrize("different", [False, True])
def test_bound_container_route_parity_ignores_operator_environment_and_redacts(
    monkeypatch, caplog, optimize, different
):
    monkeypatch.setenv("HTTP_PROXY", "http://operator-secret@unrelated-operator:9999")
    deployed = {
        "bound-backend-id": {
            **DEPLOYED,
            "HTTP_PROXY": "http://user:backend-secret@proxy-a:8888",
        },
        "bound-bridge-id": {
            **DEPLOYED,
            "http_proxy": "http://user:backend-secret@proxy-a:8888",
        },
    }
    if different:
        deployed["bound-bridge-id"]["http_proxy"] = (
            "http://user:bridge-secret@proxy-b:8888"
        )
    calls = []
    outputs = []
    caplog.set_level("DEBUG")

    def run(command, **kwargs):
        calls.append((command, kwargs))
        output = io.StringIO()
        with monkeypatch.context() as context, redirect_stdout(output):
            context.setattr(os, "environ", deployed[command[3]])
            context.setattr(sys, "stdin", io.StringIO(kwargs["input"]))
            with pytest.raises(SystemExit) as error:
                exec(
                    compile(
                        command[6], "<container-storage>", "exec", optimize=optimize
                    ),
                    {"__name__": "__main__"},
                )
        outputs.append(output.getvalue())
        return SimpleNamespace(returncode=error.value.code, stdout=output.getvalue())

    monkeypatch.setattr(readiness.subprocess, "run", run)
    assert (
        readiness.qdrant_routing_matches("bound-backend-id", "bound-bridge-id")
        is not different
    )
    assert [call[0][3] for call in calls] == list(deployed)
    assert all(
        json.loads(options["input"]) == {"operation": "qdrant-route"}
        for _, options in calls
    )
    visible = json.dumps(calls) + json.dumps(outputs) + caplog.text
    assert all(
        secret not in visible
        for secret in ("operator-secret", "backend-secret", "bridge-secret")
    )


@pytest.mark.parametrize(
    "output,code",
    [("{}", 0), ('{"status":"FAIL"}', 1), ('{"status":"PASS","route_sha256":""}', 0)],
)
def test_missing_or_failed_route_cannot_certify_parity(monkeypatch, output, code):
    monkeypatch.setattr(
        readiness.subprocess,
        "run",
        lambda *a, **k: SimpleNamespace(returncode=code, stdout=output),
    )
    assert not readiness.qdrant_routing_matches("bound-backend-id", "bound-bridge-id")


@pytest.mark.parametrize("optimize", [0, 1, 2])
def test_invalid_proxy_fails_without_disclosing_credentials(
    monkeypatch, capsys, optimize
):
    monkeypatch.setattr(
        os,
        "environ",
        {
            **DEPLOYED,
            "HTTP_PROXY": "http://private-user:private-password@proxy:invalid-port",
        },
    )
    monkeypatch.setattr(sys, "stdin", io.StringIO('{"operation":"qdrant-route"}'))
    with pytest.raises(SystemExit) as error:
        exec(
            compile(
                Path(storage.__file__).read_text(),
                "<container-storage>",
                "exec",
                optimize=optimize,
            ),
            {"__name__": "__main__"},
        )
    assert error.value.code == 1
    assert json.loads(capsys.readouterr().out) == {"status": "FAIL"}
