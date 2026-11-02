"""Read-only storage probe, executed inside the BFF-bound backend container.

Only status or an opaque route digest leaves the container. Runtime loaders own
defaults and credentials; operator input can confirm but not select destinations.
"""

from __future__ import annotations

import hashlib
import json
import math
import os
import sys
from typing import Any
from urllib.parse import quote, urlsplit

import requests
from neo4j import GraphDatabase, Query, READ_ACCESS
from requests.utils import get_auth_from_url, prepend_scheme_if_needed, select_proxy
from urllib3.util import parse_url

from hawki_graph_store.settings import Neo4jSettings, load_neo4j_settings
from hawki_vector_store.settings import QdrantSettings, qdrant_settings_from_env


def canonical_storage_url(value: str, *, graph: bool = False) -> str:
    defaults = (
        {
            name: 7687
            for name in ("bolt", "bolt+s", "bolt+ssc", "neo4j", "neo4j+s", "neo4j+ssc")
        }
        if graph
        else {"http": 80, "https": 443}
    )
    parts = urlsplit(value)
    if (
        any(ord(c) <= 32 or ord(c) == 127 for c in value)
        or value.split(":", 1)[0] not in defaults
        or not parts.hostname
        or parts.username is not None
        or parts.password is not None
        or parts.path not in {"", "/"}
        or any(c in value for c in ("?", "#", "\\", "%"))
    ):
        raise ValueError("Invalid storage identity.")
    port = parts.port if parts.port is not None else defaults[parts.scheme]
    if not 1 <= port <= 65535:
        raise ValueError("Invalid storage port.")
    host = parts.hostname.lower()
    if ":" in host:
        host = f"[{host}]"
    return f"{parts.scheme}://{host}:{port}"


def effective_storage(
    confirmations: dict[str, str],
) -> tuple[QdrantSettings, Neo4jSettings]:
    # Require explicit deployment identity; do not certify implicit home DBs or
    # accidental developer defaults. Parsing still belongs to runtime loaders.
    required = (
        "QDRANT_SCHEME",
        "QDRANT_HOST",
        "QDRANT_PORT",
        "NEO4J_URI",
        "NEO4J_PASSWORD",
        "NEO4J_DATABASE",
    )
    if not all(os.environ.get(name, "").strip() for name in required):
        raise ValueError("Explicit deployed storage identity required.")
    if not (os.environ.get("NEO4J_USER") or os.environ.get("NEO4J_USERNAME")):
        raise ValueError("Deployed graph authentication required.")
    vector = qdrant_settings_from_env()
    graph = load_neo4j_settings()
    qdrant_url = canonical_storage_url(vector.base_url)
    neo4j_uri = canonical_storage_url(graph.uri, graph=True)
    if not graph.database or not graph.user or not graph.password:
        raise ValueError("Deployed graph database and authentication required.")
    if not math.isfinite(vector.timeout) or vector.timeout <= 0:
        raise ValueError("Invalid storage timeout.")
    for name, target, is_graph in (
        ("HAWKI_PARITY_QDRANT_URL", qdrant_url, False),
        ("HAWKI_PARITY_NEO4J_URI", neo4j_uri, True),
        ("HAWKI_PARITY_NEO4J_URL", neo4j_uri, True),
    ):
        value = confirmations.get(name)
        if value and canonical_storage_url(value, graph=is_graph) != target:
            raise ValueError("Storage confirmation differs from deployment.")
    for name, target in (
        ("HAWKI_PARITY_NEO4J_DATABASE", graph.database),
        ("HAWKI_PARITY_QDRANT_API_KEY", vector.api_key or ""),
        ("HAWKI_PARITY_NEO4J_AUTH", f"{graph.user}:{graph.password}"),
    ):
        if confirmations.get(name) and confirmations[name] != target:
            raise ValueError("Storage confirmation differs from deployment.")
    return vector, graph


def check_inventory(rows: list[dict[str, Any]], confirmations: dict[str, str]) -> None:
    vector, graph = effective_storage(confirmations)
    if not rows:
        raise ValueError("Live inventory required.")
    headers = {"api-key": vector.api_key} if vector.api_key else {}
    # Match QdrantHTTPTransport's Requests session, including environment proxy
    # selection, NO_PROXY handling and TLS trust. Only redirect policy differs:
    # inventory must never certify a destination reached through a redirect.
    with requests.Session() as client:
        client.headers.update(headers)
        for row in rows:
            collection = quote(row["qdrant_collection"], safe="")
            response = client.get(
                f"{vector.base_url}/collections/{collection}",
                timeout=vector.timeout,
                allow_redirects=False,
            )
            if response.status_code != 200:
                raise ValueError("Collection unavailable.")
            response = client.post(
                f"{vector.base_url}/collections/{collection}/points/scroll",
                timeout=vector.timeout,
                allow_redirects=False,
                json={
                    "filter": {
                        "must": [
                            {"key": "dataset_id", "match": {"value": row["dataset_id"]}}
                        ]
                    },
                    "limit": 1,
                    "with_payload": True,
                },
            )
            if response.status_code != 200 or not response.json().get("result", {}).get(
                "points"
            ):
                raise ValueError("Dataset inventory unavailable.")
    graph_rows = [row for row in rows if row["graph_enabled"]]
    if graph_rows:
        with GraphDatabase.driver(
            graph.uri,
            auth=(graph.user, graph.password),
            max_transaction_retry_time=graph.max_transaction_retry_time,
        ) as driver:
            with driver.session(
                database=graph.database, default_access_mode=READ_ACCESS
            ) as session:
                for row in graph_rows:
                    record = session.run(
                        Query(
                            "MATCH (n) WHERE n.dataset_id = $dataset_id "
                            "AND n.neo4j_namespace = $namespace RETURN count(n) AS count",
                            timeout=30,
                        ),
                        dataset_id=row["dataset_id"],
                        namespace=row["neo4j_namespace"],
                    ).single(strict=True)
                    if record is None or record["count"] <= 0:
                        raise ValueError("Graph inventory unavailable.")


def qdrant_route_digest() -> str:
    """Compare effective routes without exporting proxy URLs or credentials.

    Execute in each bound container so its installed Requests/urllib behavior
    and deployed environment determine precedence, bypass and proxy selection.
    Values ignored by Requests do not cause false disagreement.
    """
    vector = qdrant_settings_from_env()
    target = canonical_storage_url(vector.base_url)
    with requests.Session() as session:
        url = session.prepare_request(
            requests.Request("GET", f"{vector.base_url}/collections")
        ).url
        settings = session.merge_environment_settings(url, {}, False, None, None)
        selected = select_proxy(url, settings["proxies"])
        proxy = None
        if selected:
            selected = prepend_scheme_if_needed(selected, "http")
            # Use the production adapter to reject unsupported/invalid proxies,
            # without opening a connection. It also checks optional SOCKS support.
            adapter = session.get_adapter(url)
            adapter.proxy_manager_for(selected)
            parsed = parse_url(selected)
            defaults = {"http": 80, "https": 443}
            default_port = (
                1080 if parsed.scheme.startswith("socks") else defaults[parsed.scheme]
            )
            if not parsed.host:
                raise ValueError("Invalid Qdrant route.")
            proxy = {
                "scheme": parsed.scheme,
                "host": parsed.host.lower(),
                "port": parsed.port if parsed.port is not None else default_port,
                "auth": get_auth_from_url(selected)
                if parsed.scheme.startswith("socks")
                else adapter.proxy_headers(selected),
            }
        route = {"target": target, "proxy": proxy}
    return hashlib.sha256(
        json.dumps(route, sort_keys=True, separators=(",", ":")).encode()
    ).hexdigest()


def main() -> int:
    try:
        request = json.load(sys.stdin)
        if request.get("operation") == "qdrant-route":
            print(json.dumps({"status": "PASS", "route_sha256": qdrant_route_digest()}))
            return 0
        if request["inventory"] is None:
            effective_storage(request["confirmations"])
        else:
            check_inventory(request["inventory"], request["confirmations"])
        print(json.dumps({"status": "PASS"}))
        return 0
    except Exception:
        # Driver/HTTP exceptions may contain credentials. Never serialize them.
        print(json.dumps({"status": "FAIL"}))
        return 1


if __name__ == "__main__":
    sys.exit(main())
