"""Read-only deployment gates for query_grants.py; never a runtime dependency."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any

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


def mounted_key_isolated(
    report: dict[str, Any], inspections: list[dict[str, Any]], repository: Path
) -> bool:
    """Reject shared, writable, or checkout-backed private-key mounts."""
    app = next(i for i in inspections if i["Name"] == "/hawki_rag_app")
    key = report.get("key") or {}
    destination = Path(key.get("mount_path", "/run/secrets/query-bff/private.pem"))
    mounts = [
        m
        for m in app["Mounts"]
        if destination == Path(m["Destination"])
        or Path(m["Destination"]) in destination.parents
    ]
    if not mounts or key.get("bits", 0) < 2048 or not key.get("fingerprint"):
        return False
    mount = max(mounts, key=lambda m: len(m["Destination"]))
    source = Path(mount["Source"]).resolve()
    if (
        mount["RW"]
        or source == repository
        or repository in source.parents
        or source in repository.parents
    ):
        return False
    for inspection in inspections:
        if inspection["Id"] == app["Id"]:
            continue
        for other in inspection["Mounts"]:
            path = Path(other["Source"]).resolve()
            if path == source or source in path.parents or path in source.parents:
                return False
    return True


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
    assert keys and all(set(k) <= {'kid','kty','alg','use','key_ops','n','e'} for k in keys)
    assert all(k.get('kty') == 'RSA' and k.get('alg') == 'RS256' and k.get('use') == 'sig' and k.get('key_ops') == ['verify'] for k in keys)
    assert len({k['kid'] for k in keys}) == len(keys)
    key = JwksKeyProvider(settings).get_key(sys.argv[1], 'RS256')
    assert key is not None and key.key_size >= 2048
    numbers = key.public_numbers()
    def encode(number):
        return base64.urlsafe_b64encode(number.to_bytes((number.bit_length()+7)//8, 'big')).decode().rstrip('=')
    fingerprint = hashlib.sha256((encode(numbers.n)+':'+encode(numbers.e)).encode()).hexdigest()
    assert fingerprint == sys.argv[2]
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
        assert all(row['tenant_id'] == 'default' for row in ds + gs)
        state = {'datasets': {r['dataset_id']: r['status'] for r in ds},
                 'grants': sorted((r['dataset_id'], r['principal_type'], r['principal_id']) for r in gs if r['permission'] == 'query')}
    engine.dispose()
    digest = hashlib.sha256(json.dumps(state, sort_keys=True, separators=(',',':')).encode()).hexdigest()
    print(json.dumps({'status':'PASS','digest':digest}))
except Exception:
    print(json.dumps({'status':'FAIL'}))
    sys.exit(1)
"""


def deployed_target_matches(plan: Any) -> bool:
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
        ["docker", "exec", "hawki_backend", "python", "-c", BACKEND_STATE_CHECK]
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
                "hawki_backend",
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
        check("deployed_backend_data", lambda: deployed_target_matches(plan))
        bridge = container_environment("hawki_rag_bridge")
        shared = shared_environment(bridge, env)
        checks["shared_environment"] = (
            "FAIL"
            if "FAIL" in shared.values()
            else "NOT EXECUTED"
            if "NOT EXECUTED" in shared.values()
            else "PASS"
        )
        check("reranker", lambda: reranker_matches(load_query_settings(env), bridge))
    else:
        checks.update(
            shared_environment="NOT EXECUTED",
            reranker="NOT EXECUTED",
            deployed_backend_data="NOT EXECUTED",
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
