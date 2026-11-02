"""Read-only deployment gates for query_grants.py; never a runtime dependency."""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path, PurePosixPath
from typing import Any
from urllib.parse import urlsplit

from hawki_backend.auth.models import PrincipalKind
from hawki_backend.query.settings import load_query_settings
from hawki_backend.settings import load_auth_settings

REQUIRED_RAG = frozenset(
    """
RAG_GENERATE_ANSWER RAG_FUSION_SEM_WEIGHT RAG_FUSION_STR_WEIGHT
RAG_MIN_SCORE RAG_MIN_SCORE_FALLBACK RAG_CONTEXT_TOKENS RAG_CONTEXT_DOCS
RAG_ITERATIVE_RETRIEVAL RAG_STRUCTURAL_LIMIT RAG_STRUCTURAL_HOPS
RAG_GRAPH_TERMS_PER_HIT RAG_GRAPH_TERM_LIMIT RAG_SEARCH_TOP_K_MULT
RAG_SEARCH_TOP_K_CAP RAG_EXHAUSTIVE_TEXT QDRANT_TEXT_SCROLL_LIMIT
""".split()
)
PREFIXES = (
    "RAG_",
    "QDRANT_",
    "NEO4J_",
    "RERANKER_",
    "OLLAMA_",
    "LITELLM_",
    "OPENAI_",
    "ANTHROPIC_",
    "JINA_",
    "GRAPH_",
    "EMBEDDING_",
    "GEMINI_",
    "GOOGLE_",
    "AZURE_",
    "COHERE_",
    "VOYAGE_",
)


def run_json(command: list[str]) -> Any:
    result = subprocess.run(
        command, capture_output=True, text=True, timeout=45, check=False
    )
    if result.returncode:
        raise ValueError("Deployment inspection failed.")
    return json.loads(result.stdout)


def container_inspect(name: str) -> dict[str, Any]:
    return run_json(["docker", "inspect", name])[0]


def container_environment(name: str) -> dict[str, str]:
    """Keep values in memory; never log docker inspect output."""
    return dict(
        item.split("=", 1)
        for item in container_inspect(name)["Config"]["Env"]
        if "=" in item
    )


def probe_storage(backend_id: str, inventory: list[dict] | None = None) -> bool:
    """Use runtime loaders and network namespace of the already-bound backend."""
    names = (
        "HAWKI_PARITY_QDRANT_URL",
        "HAWKI_PARITY_QDRANT_API_KEY",
        "HAWKI_PARITY_NEO4J_URI",
        "HAWKI_PARITY_NEO4J_URL",
        "HAWKI_PARITY_NEO4J_DATABASE",
        "HAWKI_PARITY_NEO4J_AUTH",
    )
    program = Path(__file__).with_name("query_storage.py").read_text()
    response = subprocess.run(
        ["docker", "exec", "-i", backend_id, "python", "-c", program],
        input=json.dumps(
            {
                "inventory": inventory,
                "confirmations": {
                    name: os.environ[name] for name in names if name in os.environ
                },
            }
        ),
        capture_output=True,
        text=True,
        timeout=180,
        check=False,
    )
    return response.returncode == 0 and json.loads(response.stdout) == {
        "status": "PASS"
    }


def qdrant_routing_matches(backend_id: str, bridge_id: str) -> bool:
    """Resolve routing in the bound deployments, never in the operator shell."""
    program = Path(__file__).with_name("query_storage.py").read_text()
    routes = []
    for container_id in (backend_id, bridge_id):
        result = subprocess.run(
            ["docker", "exec", "-i", container_id, "python", "-c", program],
            input=json.dumps({"operation": "qdrant-route"}),
            capture_output=True,
            text=True,
            timeout=45,
            check=False,
        )
        if result.returncode:
            return False
        response = json.loads(result.stdout)
        digest = response.get("route_sha256")
        if (
            response.get("status") != "PASS"
            or not isinstance(digest, str)
            or re.fullmatch(r"[0-9a-f]{64}", digest) is None
        ):
            return False
        routes.append(digest)
    return routes[0] == routes[1]


def canonical_query_url(value: Any) -> str:
    """Normalize host case and default ports without widening Laravel's URL rules."""
    if not isinstance(value, str) or any(ord(c) <= 32 or ord(c) == 127 for c in value):
        raise ValueError("Invalid BFF target.")
    parts = urlsplit(value)
    if (
        value.split(":", 1)[0] not in {"http", "https"}
        or not parts.hostname
        or parts.username is not None
        or parts.password is not None
        or parts.path != "/v1/query"
        or parts.query
        or parts.fragment
        or "?" in value
        or "#" in value
        or "\\" in value
    ):
        raise ValueError("Invalid BFF target.")
    port = (
        parts.port
        if parts.port is not None
        else (443 if parts.scheme == "https" else 80)
    )
    if port < 1 or port > 65535:
        raise ValueError("Invalid BFF port.")
    return f"{parts.scheme}://{parts.hostname.lower()}:{port}/v1/query"


def matching_probe_url(target: str, override: str | None) -> str:
    """Legacy operator base URLs are assertions, never alternate destinations."""
    canonical = canonical_query_url(target)
    if override:
        candidate = override
        if urlsplit(candidate).path in {"", "/"}:
            candidate = candidate.rstrip("/") + "/v1/query"
        if canonical_query_url(candidate) != canonical:
            raise ValueError("Live probe target differs from the BFF target.")
    return canonical


def bound_backend(report: dict[str, Any]) -> tuple[str, str]:
    """Bind effective Laravel configuration to this directly addressed Compose service.

    Custom proxies, published ports and external backends are deliberately not
    certified by this local deployment checker. No service-name-only inference.
    """
    url = matching_probe_url(
        report["python_query_url"], os.environ.get("HAWKI_PARITY_PYTHON_URL")
    )
    if url != "http://hawki_backend:80/v1/query":
        raise ValueError("Cutover requires the directly addressed Compose backend.")
    fresh = run_json(
        ["docker", "exec", "hawki_rag_app", "php", "artisan", "query:cutover-export"]
    )
    if canonical_query_url(fresh["python_query_url"]) != url:
        raise ValueError("Effective BFF target changed; refresh the report.")
    if (
        not isinstance(report.get("key_forbidden_paths"), list)
        or fresh.get("key_forbidden_paths") != report["key_forbidden_paths"]
    ):
        raise ValueError(
            "Effective private-key exclusion paths changed; refresh the report."
        )
    app = container_inspect("hawki_rag_app")
    backend = container_inspect("hawki_backend")
    app_labels = app["Config"]["Labels"]
    labels = backend["Config"]["Labels"]
    project = app_labels["com.docker.compose.project"]
    if (
        not project
        or labels["com.docker.compose.project"] != project
        or labels["com.docker.compose.service"] != "hawki_backend"
        or app_labels["com.docker.compose.service"] != "hawki_rag_app"
        or not backend["State"]["Running"]
    ):
        raise ValueError("Backend Compose identity mismatch.")
    # Guzzle's CLI client can honor proxy environment variables; a proxy
    # could redirect the BFF request despite matching URL and local DNS.
    proxy_names = {
        "HTTP_PROXY",
        "http_proxy",
        "HTTPS_PROXY",
        "https_proxy",
        "ALL_PROXY",
        "all_proxy",
    }
    for setting in app["Config"]["Env"]:
        name, separator, value = setting.partition("=")
        if separator and name in proxy_names and value:
            raise ValueError("BFF proxy configuration prevents direct backend binding.")
    app_networks = app["NetworkSettings"]["Networks"]
    networks = backend["NetworkSettings"]["Networks"]
    addresses = {
        network["IPAddress"]
        for name, network in networks.items()
        if name in app_networks and network["IPAddress"]
    }
    resolved = run_json(
        [
            "docker",
            "exec",
            app["Id"],
            "php",
            "-r",
            "echo json_encode(gethostbynamel($argv[1]));",
            "hawki_backend",
        ]
    )
    if not resolved or not set(resolved) <= addresses:
        raise ValueError(
            "BFF DNS does not resolve exclusively to the inspected backend."
        )
    return backend["Id"], url


def canonical_bridge_query_url(value: Any) -> str:
    """Represent the exact legacy /query endpoint, with no path rewriting."""
    if not isinstance(value, str) or any(ord(c) <= 32 or ord(c) == 127 for c in value):
        raise ValueError("Invalid legacy bridge target.")
    parts = urlsplit(value)
    if (
        value.split(":", 1)[0] not in {"http", "https"}
        or not parts.hostname
        or parts.username is not None
        or parts.password is not None
        or parts.path != "/query"
        or parts.query
        or parts.fragment
        or "?" in value
        or "#" in value
        or "\\" in value
    ):
        raise ValueError("Invalid legacy bridge target.")
    port = (
        parts.port
        if parts.port is not None
        else (443 if parts.scheme == "https" else 80)
    )
    if not 1 <= port <= 65535:
        raise ValueError("Invalid legacy bridge port.")
    return f"{parts.scheme}://{parts.hostname.lower()}:{port}/query"


def matching_bridge_target(target: str, override: str | None) -> str:
    """An operator URL confirms Laravel's destination; it never selects one."""
    canonical = canonical_bridge_query_url(target)
    if override:
        candidate = override
        if urlsplit(candidate).path in {"", "/"}:
            candidate = candidate.rstrip("/") + "/query"
        if canonical_bridge_query_url(candidate) != canonical:
            raise ValueError("Live parity bridge differs from Laravel's legacy target.")
    return canonical


def bound_bridge(report: dict[str, Any]) -> tuple[str, str]:
    """Bind parity and the inspected bridge to Laravel's real rollback route."""
    target = matching_bridge_target(
        report["legacy_bridge_query_url"], os.environ.get("HAWKI_PARITY_BRIDGE_URL")
    )
    if target != "http://hawki_rag_bridge:80/query":
        raise ValueError("Cutover requires the directly addressed Compose bridge.")
    fresh = run_json(
        ["docker", "exec", "hawki_rag_app", "php", "artisan", "query:cutover-export"]
    )
    if canonical_bridge_query_url(fresh["legacy_bridge_query_url"]) != target:
        raise ValueError("Effective legacy bridge target changed; refresh the report.")
    app = container_inspect("hawki_rag_app")
    bridge = container_inspect("hawki_rag_bridge")
    app_labels = app["Config"]["Labels"]
    labels = bridge["Config"]["Labels"]
    if (
        not app_labels["com.docker.compose.project"]
        or labels["com.docker.compose.project"]
        != app_labels["com.docker.compose.project"]
        or labels["com.docker.compose.service"] != "hawki_rag_bridge"
        or app_labels["com.docker.compose.service"] != "hawki_rag_app"
        or not bridge["State"]["Running"]
    ):
        raise ValueError("Legacy bridge Compose identity mismatch.")
    proxy_names = {
        "HTTP_PROXY",
        "http_proxy",
        "HTTPS_PROXY",
        "https_proxy",
        "ALL_PROXY",
        "all_proxy",
    }
    if any(
        item.partition("=")[0] in proxy_names and bool(item.partition("=")[2])
        for item in app["Config"]["Env"]
    ):
        raise ValueError("Laravel proxy configuration prevents direct bridge binding.")
    app_networks = app["NetworkSettings"]["Networks"]
    addresses = {
        network["IPAddress"]
        for name, network in bridge["NetworkSettings"]["Networks"].items()
        if name in app_networks and network["IPAddress"]
    }
    resolved = run_json(
        [
            "docker",
            "exec",
            app["Id"],
            "php",
            "-r",
            "echo json_encode(gethostbynamel($argv[1]));",
            "hawki_rag_bridge",
        ]
    )
    if not resolved or not set(resolved) <= addresses:
        raise ValueError("Laravel DNS does not resolve to the inspected bridge.")
    return bridge["Id"], target


def shared_environment(
    bridge: dict[str, str], backend: dict[str, str]
) -> dict[str, str]:
    names = REQUIRED_RAG | {
        k for env in (bridge, backend) for k in env if k.startswith(PREFIXES)
    }
    return {
        name: "NOT EXECUTED"
        if name not in bridge or name not in backend
        else "PASS"
        if bridge[name] == backend[name]
        else "FAIL"
        for name in sorted(names)
    }


def reranker_matches(query: Any, bridge: dict[str, str]) -> bool:
    # The bridge HTTP request omits these fields on Laravel's legacy path.
    mode = (bridge.get("RERANKER_MODE", "none") or "none").strip()
    raw_mix = str(bridge.get("RERANKER_MIX_MODE", "")).strip().lower()
    mix = not raw_mix or raw_mix in {
        "1",
        "true",
        "yes",
        "on",
    }
    weight = float((bridge.get("RERANKER_MIX_WEIGHT", "0.5") or "0.5").strip())
    return (
        query.reranker == mode
        and query.rerank_top_n == 20
        and query.reranker_mix_mode == mix
        and query.reranker_mix_weight == weight
        and (mode != "external" or bool(bridge.get("RERANKER_API_URL", "").strip()))
    )


def auth_matches(report: dict[str, Any], env: dict[str, str]) -> bool:
    auth = load_auth_settings(env)
    bff = report["bff"]
    return bool(
        auth is not None
        and bff["tenant"] == report["tenant"] == "default"
        and bff["issuer"] == auth.issuer
        and bff["audience"] == auth.audience
        and bff["client_id"] == "hawki-bff"
        and auth.clients.get("hawki-bff") == PrincipalKind.USER
        and auth.tenant_claim == "tenant_id"
        and auth.algorithms == ("RS256",)
        and 1 <= int(bff["token_ttl"]) <= min(60, auth.max_token_ttl_seconds)
        and bool(bff["key_id"])
        and auth.jwks_url.startswith("https://")
    )


def normalized_path(value: Any) -> PurePosixPath:
    """Accept only unambiguous absolute container/host mount paths."""
    if (
        not isinstance(value, str)
        or not value.startswith("/")
        or value.startswith("//")
    ):
        raise ValueError("Absolute mount path required.")
    path = PurePosixPath(value)
    if (
        str(path) != value
        or ".." in path.parts
        or any(ord(c) < 32 or ord(c) == 127 for c in value)
    ):
        raise ValueError("Ambiguous mount path.")
    return path


def overlaps(left: PurePosixPath, right: PurePosixPath) -> bool:
    return left == right or left in right.parents or right in left.parents


def mounted_key_isolated(
    report: dict[str, Any], inspections: list[dict[str, Any]], repository: Path
) -> bool:
    """Require a dedicated secret mount, with no source or destination aliases."""
    try:
        apps = [i for i in inspections if i["Name"] == "/hawki_rag_app"]
        if len(apps) != 1:
            return False
        app = apps[0]
        key = report["key"]
        destination = normalized_path(key["mount_path"])
        # This deployment supports private keys only in the dedicated secret tree.
        # Do not attempt to infer whether arbitrary Nginx aliases are safe.
        if PurePosixPath("/run/secrets") not in destination.parents:
            return False
        if (
            not isinstance(report.get("key_forbidden_paths"), list)
            or not report["key_forbidden_paths"]
        ):
            return False
        forbidden = [
            "/var/www/html/public",
            "/var/www/html/storage",
            "/shared",
            "/var/www/built_resources",
            "/run/query-bff-public",
        ]
        forbidden.extend(report["key_forbidden_paths"])
        for setting in app.get("Config", {}).get("Env", []):
            name, _, value = setting.partition("=")
            if name in {
                "WEB_DOCUMENT_ROOT",
                "DOCUMENT_ROOT",
                "HAWKI_RAG_TEMPORAL_SHARED_ROOT",
            }:
                forbidden.append(value)
        if any(overlaps(destination, normalized_path(path)) for path in forbidden):
            return False
        mounts = []
        for inspection in inspections:
            for mount in inspection["Mounts"]:
                source = normalized_path(
                    str(Path(normalized_path(mount["Source"])).resolve())
                )
                target = normalized_path(mount["Destination"])
                if type(mount["RW"]) is not bool:
                    return False
                mounts.append((inspection["Id"], source, target, mount["RW"]))
        candidates = [
            m
            for m in mounts
            if m[0] == app["Id"]
            and (m[2] == destination or m[2] in destination.parents)
        ]
        if len(candidates) != 1 or key["bits"] < 2048 or not key["fingerprint"]:
            return False
        selected = candidates[0]
        _, source, target, writable = selected
        if writable or overlaps(source, normalized_path(str(repository.resolve()))):
            return False
        # A mount covering public/storage roots is unsafe even if the key itself
        # sits elsewhere beneath that mount.
        if any(overlaps(target, normalized_path(path)) for path in forbidden):
            return False
        for other in mounts:
            if other is selected:
                continue
            if overlaps(source, other[1]):
                return False
            if other[0] == app["Id"] and overlaps(target, other[2]):
                return False
        return True
    except (KeyError, TypeError, ValueError):
        return False


# Executed inside the backend container so TLS trust/network/auth settings are real.
# No private key or JWT crosses stdout; use a fresh production JWKS provider.
JWKS_CHECK = """
import base64, hashlib, json, os, sys
import httpx
from hawki_backend.settings import load_auth_settings
from hawki_backend.auth.jwks import JwksKeyProvider
try:
    settings = load_auth_settings()
    response = httpx.get(settings.jwks_url, timeout=10, follow_redirects=False)
    response.raise_for_status()
    keys = response.json()['keys']
    if not (keys and all(set(k) <= {'kid','kty','alg','use','key_ops','n','e'} for k in keys)):
        raise ValueError("Unsafe readiness state.")
    if not (all(k.get('kty') == 'RSA' and k.get('alg') == 'RS256' and k.get('use') == 'sig' and k.get('key_ops') == ['verify'] for k in keys)):
        raise ValueError("Unsafe readiness state.")
    if not (len({k['kid'] for k in keys}) == len(keys)):
        raise ValueError("Unsafe readiness state.")
    key = JwksKeyProvider(settings).get_key(sys.argv[1], 'RS256')
    if not (key is not None and key.key_size >= 2048):
        raise ValueError("Unsafe readiness state.")
    numbers = key.public_numbers()
    def encode(number):
        return base64.urlsafe_b64encode(number.to_bytes((number.bit_length()+7)//8, 'big')).decode().rstrip('=')
    fingerprint = hashlib.sha256((encode(numbers.n)+':'+encode(numbers.e)).encode()).hexdigest()
    if not (fingerprint == sys.argv[2]):
        raise ValueError("Unsafe readiness state.")
    print(json.dumps({'status':'PASS'}))
except Exception:
    print(json.dumps({'status':'FAIL'}))
    sys.exit(1)
"""


BACKEND_STATE_CHECK = """
import hashlib, json, sys
import sqlalchemy as sa
from hawki_backend.settings import load_database_settings
from hawki_backend.persistence.engine import build_engine
from hawki_backend.persistence.schema import datasets, dataset_grants
try:
    engine = build_engine(load_database_settings())
    with engine.connect() as conn, conn.begin():
        conn.execute(sa.text('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'))
        ds = list(conn.execute(sa.select(datasets)).mappings())
        gs = list(conn.execute(sa.select(dataset_grants)).mappings())
        if not (all(row['tenant_id'] == 'default' for row in ds + gs)):
            raise ValueError("Unsafe readiness state.")
        state = {'datasets': {r['dataset_id']: r['status'] for r in ds},
                 'grants': sorted((r['dataset_id'], r['principal_type'], r['principal_id']) for r in gs if r['permission'] == 'query')}
    engine.dispose()
    digest = hashlib.sha256(json.dumps(state, sort_keys=True, separators=(',',':')).encode()).hexdigest()
    print(json.dumps({'status':'PASS','digest':digest}))
except Exception:
    print(json.dumps({'status':'FAIL'}))
    sys.exit(1)
"""


def deployed_target_matches(plan: Any, backend_id: str) -> bool:
    """Prove the runtime DB matches, even when host/operator DSNs differ."""
    import hashlib

    state = {
        "datasets": plan.dataset_status,
        "grants": sorted((d, "user", u) for d, u in plan.grants),
    }
    expected = hashlib.sha256(
        json.dumps(state, sort_keys=True, separators=(",", ":")).encode()
    ).hexdigest()
    result = run_json(
        ["docker", "exec", backend_id, "python", "-c", BACKEND_STATE_CHECK]
    )
    return result.get("status") == "PASS" and result.get("digest") == expected


def live_gates(report: dict[str, Any]) -> dict[str, str]:
    """Only explicitly invoked for operator-approved staging. No flags are changed."""
    gates = {
        "private_key_mounts": "FAIL",
        "https_jwks_refresh_key_match": "NOT EXECUTED",
        "live_parity_and_admission": "NOT EXECUTED",
    }
    try:
        backend_id, _ = bound_backend(report)
        bound_bridge(report)
        names = subprocess.run(
            ["docker", "ps", "-aq"],
            capture_output=True,
            text=True,
            timeout=15,
            check=True,
        ).stdout.split()
        inspections = run_json(["docker", "inspect", *names])
        if not mounted_key_isolated(
            report, inspections, Path(__file__).resolve().parents[2]
        ):
            return gates
        gates["private_key_mounts"] = "PASS"
        gates["https_jwks_refresh_key_match"] = "FAIL"
        checked = run_json(
            [
                "docker",
                "exec",
                backend_id,
                "python",
                "-c",
                JWKS_CHECK,
                report["bff"]["key_id"],
                report["key"]["fingerprint"],
            ]
        )
        gates["https_jwks_refresh_key_match"] = checked["status"]
        if checked["status"] != "PASS":
            return gates
        # The tests read a fresh operator inventory and hit the real signed BFF path.
        # Require every gate to execute; missing inputs never become successful skips.
        gates["live_parity_and_admission"] = "FAIL"
        with tempfile.TemporaryDirectory(prefix="query-cutover-") as directory:
            result_file = Path(directory) / "result.xml"
            result = subprocess.run(
                [
                    sys.executable,
                    "-m",
                    "pytest",
                    "tests/parity/test_live_scope_integration.py",
                    "-q",
                    f"--junitxml={result_file}",
                ],
                cwd=Path(__file__).resolve().parents[1],
                env={**os.environ, "HAWKI_PARITY_INTEGRATION": "1"},
                capture_output=True,
                text=True,
                timeout=1800,
                check=False,
            )
            suites = ET.parse(result_file).getroot()
            cases = list(suites.iter("testcase"))
            if (
                result.returncode == 0
                and len(cases) == 4
                and not any(
                    list(suites.iter(tag)) for tag in ("skipped", "failure", "error")
                )
            ):
                gates["live_parity_and_admission"] = "PASS"
        return gates
    except Exception:
        return gates


def check_readiness(
    connection: Any,
    plan: Any,
    report: dict[str, Any],
    env: dict[str, str],
    *,
    inspect_containers: bool,
    live: bool,
    phase: str,
) -> dict[str, Any]:
    from query_grants import compare_target, verify_scope

    checks: dict[str, str] = {}

    def check(name: str, operation: Any) -> None:
        try:
            checks[name] = "PASS" if operation() else "FAIL"
        except Exception:
            checks[name] = "FAIL"

    check("tenant", lambda: report["tenant"] == "default")
    check("backfill", lambda: compare_target(connection, plan)["status"] == "PASS")
    check("scope", lambda: verify_scope(plan, env))
    check(
        "models", lambda: json.loads(env["HAWKI_BACKEND_QUERY_MODELS"]) == plan.models
    )
    check("auth", lambda: auth_matches(report, env))
    check("selector", lambda: report["backend"] in {"laravel", "python"})
    check(
        "auth_guard_advisory",
        lambda: (
            "token" not in report["auth_guard_drivers"].values()
            and report["auth_default_guard"] in report["auth_guard_drivers"]
        ),
    )
    # Every dataset Laravel can advertise must exist in scope and effective grants.
    check(
        "discovery_coverage",
        lambda: (
            not report["query_all"]
            or all((d, u) in plan.grants for d in plan.scope for u in plan.users)
        ),
    )
    check("rollback_grants", lambda: not plan.legacy_required)
    check(
        "query_all_policy", lambda: phase == "prepare" or report["query_all"] is False
    )
    shared = {}
    if inspect_containers:
        target: tuple[str, str] | None = None
        legacy: tuple[str, str] | None = None
        try:
            target = bound_backend(report)
            checks["backend_target"] = (
                "PASS" if container_environment(target[0]) == env else "FAIL"
            )
        except Exception:
            checks["backend_target"] = "FAIL"
        check(
            "deployed_backend_data",
            lambda: target is not None and deployed_target_matches(plan, target[0]),
        )
        check(
            "storage_identity",
            lambda: target is not None and probe_storage(target[0]),
        )
        try:
            legacy = bound_bridge(report)
            checks["legacy_bridge_target"] = "PASS"
        except Exception:
            checks["legacy_bridge_target"] = "FAIL"
        if legacy is not None:
            bridge = container_environment(legacy[0])
            shared = shared_environment(bridge, env)
            try:
                shared["qdrant_routing"] = (
                    "PASS"
                    if target is not None
                    and qdrant_routing_matches(target[0], legacy[0])
                    else "FAIL"
                )
            except Exception:
                shared["qdrant_routing"] = "FAIL"
            checks["shared_environment"] = (
                "FAIL"
                if "FAIL" in shared.values()
                else "NOT EXECUTED"
                if "NOT EXECUTED" in shared.values()
                else "PASS"
            )
            check(
                "reranker", lambda: reranker_matches(load_query_settings(env), bridge)
            )
        else:
            checks["shared_environment"] = "NOT EXECUTED"
            checks["reranker"] = "NOT EXECUTED"
    else:
        checks.update(
            shared_environment="NOT EXECUTED",
            reranker="NOT EXECUTED",
            deployed_backend_data="NOT EXECUTED",
            backend_target="NOT EXECUTED",
            legacy_bridge_target="NOT EXECUTED",
            storage_identity="NOT EXECUTED",
        )
    external = {
        "private_key_mounts": "NOT EXECUTED",
        "https_jwks_refresh_key_match": "NOT EXECUTED",
        "live_parity_and_admission": "NOT EXECUTED",
    }
    if live and all(v == "PASS" for v in checks.values()):
        try:
            external = live_gates(report)
        except Exception:
            external = dict.fromkeys(external, "FAIL")
    checks.update(external)
    return {
        "status": "PASS"
        if all(value == "PASS" for value in checks.values())
        else "FAIL",
        "phase": phase,
        "checks": checks,
        "shared_names": shared,
        "note": "NOT EXECUTED means ACTIVATION PREREQUISITE. No settings or grants were changed.",
    }
