"""Opt-in live integration checks for the Epoch 7 cutover gate.

These tests are never part of the deterministic suite. They are skipped unless
``HAWKI_PARITY_INTEGRATION`` is enabled and the relevant endpoints are
configured. The bridge is contacted over plain HTTP; ``hawki_bridge`` is never
imported.
"""

from __future__ import annotations

import os
import json
import subprocess
import time
import sys
from pathlib import Path
from typing import Any

import pytest

from hawki_backend.query.settings import load_query_settings

# Operator helpers are not packaged into the backend runtime.
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
from query_readiness import (  # noqa: E402
    bound_backend,
    bound_bridge,
    canonical_query_url,
    probe_storage,
)


_INTEGRATION_ENABLED = os.environ.get(
    "HAWKI_PARITY_INTEGRATION", ""
).strip().lower() in {
    "1",
    "true",
    "yes",
}

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(
        not _INTEGRATION_ENABLED,
        reason="set HAWKI_PARITY_INTEGRATION=1 to run live parity integration",
    ),
]

_PHYSICAL_KEYS = frozenset(
    {"qdrant_collection", "qdrantCollection", "neo4j_namespace", "neo4jNamespace"}
)


def _required(name: str) -> str:
    """Return a required integration setting or fail with a clear reason."""

    value = os.environ.get(name, "").strip()
    if not value:
        pytest.fail(f"{name} is required when live parity is enabled")
    return value


def _configured_datasets() -> list[dict[str, Any]]:
    """Return the inventory entries selected for this integration run."""

    report = json.loads(Path(_required("HAWKI_PARITY_INVENTORY_FILE")).read_text())
    if not (report["version"] == 1 and report["tenant"] == "default"):
        pytest.fail("Live gate condition failed.")
    if not (0 <= time.time() - report["generated_at"] <= 300):
        pytest.fail("Refresh operator inventory")
    inventory = [row for row in report["datasets"] if row["status"] == "active"]
    if not (inventory):
        pytest.fail("Actual staging inventory is required")
    settings = _deployed_settings()
    if not (set(settings.datasets) == {r["dataset_id"] for r in inventory}):
        pytest.fail("Live gate condition failed.")
    if not (
        json.loads(_backend_environment()["HAWKI_BACKEND_QUERY_MODELS"])
        == report["models"]
    ):
        pytest.fail("Live gate condition failed.")
    for row in inventory:
        scope = settings.resolve_scope(row["dataset_id"])
        for key in (
            "qdrant_collection",
            "neo4j_namespace",
            "embedding_provider",
            "embedding_model",
        ):
            expected = (
                row[key].strip() or None if isinstance(row[key], str) else row[key]
            )
            if not (getattr(scope, key) == expected):
                pytest.fail("Live gate condition failed.")
        if not (scope.graph_enabled == row["graph_enabled"]):
            pytest.fail("Live gate condition failed.")
    return inventory


def _bound_target() -> tuple[str, str]:
    report = json.loads(Path(_required("HAWKI_PARITY_INVENTORY_FILE")).read_text())
    if not 0 <= time.time() - report["generated_at"] <= 300:
        pytest.fail("Refresh operator inventory")
    return bound_backend(report)


def _bound_bridge_target() -> tuple[str, str]:
    report = json.loads(Path(_required("HAWKI_PARITY_INVENTORY_FILE")).read_text())
    if not 0 <= time.time() - report["generated_at"] <= 300:
        pytest.fail("Refresh operator inventory")
    return bound_bridge(report)


def _backend_environment() -> dict[str, str]:
    backend_id, _ = _bound_target()
    result = subprocess.run(
        ["docker", "inspect", backend_id],
        capture_output=True,
        text=True,
        timeout=15,
    )
    if result.returncode:
        pytest.fail("Cannot inspect bound backend")
    return dict(
        item.split("=", 1)
        for item in json.loads(result.stdout)[0]["Config"]["Env"]
        if "=" in item
    )


def _deployed_settings():
    settings = load_query_settings(_backend_environment())
    if not (settings is not None):
        pytest.fail("Deployed query registry required")
    return settings


def _physical_paths(value: Any, prefix: str = "") -> list[str]:
    """Return every remaining physical identifier path."""

    paths: list[str] = []
    if isinstance(value, dict):
        for key, item in value.items():
            path = f"{prefix}.{key}" if prefix else key
            if key in _PHYSICAL_KEYS:
                paths.append(path)
            paths.extend(_physical_paths(item, path))
    elif isinstance(value, list):
        for index, item in enumerate(value):
            paths.extend(_physical_paths(item, f"{prefix}[{index}]"))
    return paths


def _legacy_response(dataset_id: str, *, fast_mode: bool) -> dict[str, Any]:
    """Query the actual Laravel rollback service, including its configured URL."""
    _bound_bridge_target()
    command = [
        "docker",
        "exec",
        "hawki_rag_app",
        "php",
        "artisan",
        "query:cutover-probe",
        _required("HAWKI_PARITY_USER_ID"),
        dataset_id,
        "--query=" + os.environ.get("HAWKI_PARITY_QUERY", "handbook policy"),
        "--legacy",
    ]
    if not fast_mode:
        command.append("--graph")
    response = subprocess.run(command, capture_output=True, text=True, timeout=330)
    if response.returncode:
        pytest.fail("Real Laravel rollback query failed")
    result = json.loads(response.stdout)
    if result["status"] != 200:
        pytest.fail("Laravel rollback bridge did not succeed")
    return result["payload"]


def _python_response(dataset_id: str, *, fast_mode: bool) -> dict[str, Any]:
    """POST the equivalent public request to the real Python boundary."""

    _bound_target()
    command = [
        "docker",
        "exec",
        "hawki_rag_app",
        "php",
        "artisan",
        "query:cutover-probe",
        _required("HAWKI_PARITY_USER_ID"),
        dataset_id,
        "--query=" + os.environ.get("HAWKI_PARITY_QUERY", "handbook policy"),
    ]
    if not fast_mode:
        command.append("--graph")
    response = subprocess.run(command, capture_output=True, text=True, timeout=330)
    if not (response.returncode == 0):
        pytest.fail("Real BFF-signed Python query failed")
    result = json.loads(response.stdout)
    if not (result["status"] == 200):
        pytest.fail("BFF query did not succeed")
    return result["payload"]


def _assert_hits_parity(python: dict[str, Any], bridge: dict[str, Any]) -> None:
    """Compare hit identity, order, score, count and kg."""

    if not (python["count"] == bridge["count"]):
        pytest.fail("Live gate condition failed.")
    if not (
        [hit["id"] for hit in python["hits"]] == [hit["id"] for hit in bridge["hits"]]
    ):
        pytest.fail("Live gate condition failed.")
    if not (
        [round(float(hit["score"]), 6) for hit in python["hits"]]
        == [round(float(hit["score"]), 6) for hit in bridge["hits"]]
    ):
        pytest.fail("Live gate condition failed.")
    if not (python["kg"] == bridge["kg"]):
        pytest.fail("Live gate condition failed.")


# Run from Laravel's network namespace and effective cached configuration. Tokens
# go through stdin, never argv/stdout. Only status and an allowlisted error leave.
_ADMISSION_PROBE = r"""
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $target = config('config.python_query_url');
    $client = Illuminate\Support\Facades\Http::acceptJson()->withoutRedirecting()
        ->connectTimeout(3)->timeout(120)->withOptions(['cookies' => false, 'http_errors' => false]);
    if ($input['token'] !== null) { $client = $client->withToken($input['token']); }
    $response = $input['health']
        ? $client->get(substr($target, 0, -strlen('/v1/query')).'/v1/health')
        : $client->post($target, $input['payload']);
    $detail = $response->json('detail');
    $error = is_array($detail) ? ($detail['error'] ?? null) : $detail;
    echo json_encode(['target' => $target, 'status' => $response->status(),
        'error' => $error === 'dataset_not_found' ? $error : null]);
} catch (Throwable) { exit(1); }
"""


def _admission_response(
    payload: dict[str, Any], token: str | None, *, health: bool = False
) -> dict[str, Any]:
    _, target = _bound_target()
    result = subprocess.run(
        ["docker", "exec", "-i", "hawki_rag_app", "php", "-r", _ADMISSION_PROBE],
        input=json.dumps({"payload": payload, "token": token, "health": health}),
        capture_output=True,
        text=True,
        timeout=135,
    )
    if result.returncode:
        pytest.fail("Bound admission probe failed")
    response = json.loads(result.stdout)
    if canonical_query_url(response["target"]) != target:
        pytest.fail("Effective BFF destination changed during admission probe")
    return response


def _controlled_tokens() -> tuple[str, str]:
    """Fixture comparison is unverified; actual baseline/negative admission verifies JWTs."""
    import jwt

    tokens = []
    for name in (
        "HAWKI_PARITY_BASELINE_TOKEN_FILE",
        "HAWKI_PARITY_WRONG_TENANT_TOKEN_FILE",
    ):
        path = Path(_required(name))
        if path.stat().st_mode & 0o077:
            pytest.fail("Protect temporary token files")
        tokens.append(path.read_text().strip())
    try:
        baseline, wrong = [
            jwt.decode(token, options={"verify_signature": False}) for token in tokens
        ]
        headers = [jwt.get_unverified_header(token) for token in tokens]
        report = json.loads(Path(_required("HAWKI_PARITY_INVENTORY_FILE")).read_text())
        bff = report["bff"]
        expected = {
            "sub": _required("HAWKI_PARITY_USER_ID"),
            "azp": bff["client_id"],
            "iss": bff["issuer"],
            "aud": bff["audience"],
            "scope": "rag:query",
            "tenant_id": "default",
        }
        valid = (
            all(baseline.get(k) == v for k, v in expected.items())
            and headers[0] == headers[1]
            and headers[0].get("alg") == "RS256"
            and headers[0].get("kid") == bff["key_id"]
            and isinstance(wrong.get("tenant_id"), str)
            and bool(wrong["tenant_id"].strip())
            and wrong["tenant_id"] != "default"
            and {k: v for k, v in baseline.items() if k != "tenant_id"}
            == {k: v for k, v in wrong.items() if k != "tenant_id"}
        )
    except (ValueError, KeyError, TypeError, jwt.PyJWTError):
        pytest.fail("Invalid controlled token fixtures")
    if not valid:
        pytest.fail(
            "Token pair must match the authorized BFF identity and differ only in tenant_id"
        )
    return tokens[0], tokens[1]


def test_real_unauthorized_and_wrong_tenant_denied() -> None:
    """Prove the same admitted identity loses access when only its tenant changes."""
    baseline, wrong = _controlled_tokens()
    payload = {
        "dataset_id": _required("HAWKI_PARITY_GRAPH_OFF_DATASET"),
        "query": "access check",
        "generate": False,
    }
    if _admission_response({}, None, health=True)["status"] != 200:
        pytest.fail("Bound backend health failed")
    if _admission_response(payload, None)["status"] != 401:
        pytest.fail("Anonymous query must fail authentication")
    if _admission_response(payload, baseline)["status"] != 200:
        pytest.fail("Baseline identity must be authorized for this dataset")
    response = _admission_response(payload, wrong)
    if response["status"] != 404 or response["error"] != "dataset_not_found":
        pytest.fail("Same identity in another tenant must receive dataset_not_found")


def test_inventory_targets_exist_in_live_storage() -> None:
    """Every dataset is inspected using the bound runtime's storage and database."""
    inventory = _configured_datasets()
    backend_id, _ = _bound_target()
    if not probe_storage(backend_id, inventory):
        pytest.fail("Bound deployed storage inventory failed")


def test_graph_disabled_parity_against_the_bridge() -> None:
    """B: graph-off hits, order, score, count and kg match the bridge."""

    dataset_id = _required("HAWKI_PARITY_GRAPH_OFF_DATASET")
    if dataset_id not in {r["dataset_id"] for r in _configured_datasets()}:
        pytest.fail("Live gate condition failed.")
    python = _python_response(dataset_id, fast_mode=True)
    bridge = _legacy_response(dataset_id, fast_mode=True)

    _assert_hits_parity(python, bridge)
    if python["retrieval"]["graph_enabled"] is not False:
        pytest.fail("Live gate condition failed.")
    if not (_physical_paths(python) == []):
        pytest.fail("Live gate condition failed.")


def test_graph_enabled_parity_and_identifier_stripping() -> None:
    """C: graph-on results match and Python never leaks physical identifiers."""

    dataset_id = _required("HAWKI_PARITY_GRAPH_ON_DATASET")
    if dataset_id not in {
        r["dataset_id"] for r in _configured_datasets() if r["graph_enabled"]
    }:
        pytest.fail("Live gate condition failed.")
    python = _python_response(dataset_id, fast_mode=False)
    bridge = _legacy_response(dataset_id, fast_mode=False)

    _assert_hits_parity(python, bridge)
    if python["retrieval"]["graph_enabled"] is not True:
        pytest.fail("Live gate condition failed.")
    if not (
        python["kg"]
        or any((str(hit["id"]).startswith("neo4j:") for hit in python["hits"]))
    ):
        pytest.fail("Choose a staging query that exercises graph retrieval")
    if not (_physical_paths(python) == []):
        pytest.fail("Live gate condition failed.")

    for hit in python["hits"]:
        payload = hit.get("payload") or {}
        if "dataset_id" in payload:
            if not (payload["dataset_id"] == dataset_id):
                pytest.fail("Live gate condition failed.")
