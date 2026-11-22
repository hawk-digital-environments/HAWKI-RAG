"""H6 regressions at Requests' real connection boundary; no network access."""

import io
import os

import pytest
import requests
from urllib3.util import connection

from hawki_vector_store.requests import QdrantRequest
from hawki_vector_store.transport import QdrantHTTPTransport

from hawki_vector_store.settings import qdrant_settings_from_env


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
def test_production_transport_honors_proxy_and_no_proxy(
    monkeypatch, connections, routing, target
):
    monkeypatch.setattr(os, "environ", {**DEPLOYED, **routing})
    vector = qdrant_settings_from_env()
    with requests.Session() as session:
        retrieval = QdrantHTTPTransport(
            base_url=vector.base_url,
            api_key=vector.api_key,
            default_timeout=vector.timeout,
            session=session,
        )
        response = retrieval.send(QdrantRequest("GET", "/collections/qa"))
    assert response.status_code == 200
    assert connections == [target]
