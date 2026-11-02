"""Deterministic tests of live-suite admission guards; no live requests run."""

import json

import pytest

from . import test_live_scope_integration as live


def test_missing_live_input_fails_instead_of_skipping(monkeypatch):
    monkeypatch.delenv("HAWKI_PARITY_INVENTORY_FILE", raising=False)
    with pytest.raises(pytest.fail.Exception):
        live._configured_datasets()


def test_real_python_probe_calls_cli_without_mock_auth_or_token_output(monkeypatch):
    from types import SimpleNamespace

    commands = []

    def run(command, **kwargs):
        commands.append(command)
        return SimpleNamespace(
            returncode=0, stdout=json.dumps({"status": 200, "payload": {"ok": True}})
        )

    monkeypatch.setattr(
        live,
        "_bound_target",
        lambda: ("backend-id", "http://hawki_backend:80/v1/query"),
    )
    monkeypatch.setenv("HAWKI_PARITY_USER_ID", "7")
    monkeypatch.setattr(live.subprocess, "run", run)
    assert live._python_response("chosen-dataset", fast_mode=False) == {"ok": True}
    assert commands[0][:6] == [
        "docker",
        "exec",
        "hawki_rag_app",
        "php",
        "artisan",
        "query:cutover-probe",
    ]
    assert "--graph" in commands[0]
    assert not any("Bearer" in arg for arg in commands[0])


def test_legacy_parity_calls_the_actual_laravel_rollback_service(monkeypatch):
    from types import SimpleNamespace

    commands = []
    monkeypatch.setattr(
        live,
        "_bound_bridge_target",
        lambda: ("bridge-id", "http://hawki_rag_bridge:80/query"),
    )
    monkeypatch.setenv("HAWKI_PARITY_USER_ID", "7")
    monkeypatch.setenv("HAWKI_PARITY_QUERY", "same query")
    monkeypatch.setenv("HAWKI_PARITY_BRIDGE_URL", "http://unrelated-bridge:9999")

    def run(command, **kwargs):
        commands.append(command)
        return SimpleNamespace(
            returncode=0, stdout=json.dumps({"status": 200, "payload": {"count": 1}})
        )

    monkeypatch.setattr(live.subprocess, "run", run)
    assert live._legacy_response("dataset-a", fast_mode=False) == {"count": 1}
    assert commands == [
        [
            "docker",
            "exec",
            "hawki_rag_app",
            "php",
            "artisan",
            "query:cutover-probe",
            "7",
            "dataset-a",
            "--query=same query",
            "--legacy",
            "--graph",
        ]
    ]
    assert "unrelated-bridge" not in " ".join(commands[0])


@pytest.fixture
def controlled_pair(tmp_path, monkeypatch):
    import time
    import jwt
    from cryptography.hazmat.primitives.asymmetric import rsa

    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    claims = {
        "sub": "7",
        "azp": "hawki-bff",
        "scope": "rag:query",
        "iss": "https://bff.example.test",
        "aud": "backend",
        "tenant_id": "default",
        "iat": int(time.time()),
        "exp": int(time.time()) + 60,
        "jti": "same-test-id",
    }
    report = {
        "generated_at": int(time.time()),
        "python_query_url": "http://hawki_backend/v1/query",
        "bff": {
            "client_id": "hawki-bff",
            "issuer": claims["iss"],
            "audience": claims["aud"],
            "key_id": "key",
        },
    }
    report_path = tmp_path / "report.json"
    report_path.write_text(json.dumps(report))
    monkeypatch.setenv("HAWKI_PARITY_INVENTORY_FILE", str(report_path))
    monkeypatch.setenv("HAWKI_PARITY_USER_ID", "7")
    monkeypatch.setenv("HAWKI_PARITY_GRAPH_OFF_DATASET", "dataset-a")

    def provision(changes=None, *, baseline_changes=None, wrong_headers=None):
        baseline = {**claims, **(baseline_changes or {})}
        wrong = {**baseline, "tenant_id": "other", **(changes or {})}
        tokens = [
            jwt.encode(baseline, key, algorithm="RS256", headers={"kid": "key"}),
            jwt.encode(
                wrong,
                key,
                algorithm="RS256",
                headers={"kid": "key", **(wrong_headers or {})},
            ),
        ]
        for name, token in zip(("BASELINE", "WRONG_TENANT"), tokens, strict=True):
            path = tmp_path / name
            path.write_text(token)
            path.chmod(0o600)
            monkeypatch.setenv(f"HAWKI_PARITY_{name}_TOKEN_FILE", str(path))
        return tuple(tokens)

    return provision


def test_controlled_pair_differs_only_in_tenant(controlled_pair):
    tokens = controlled_pair()
    assert live._controlled_tokens() == tokens


@pytest.mark.parametrize(
    "field,value",
    [
        ("sub", "UNGRANTED-OTHER-USER"),
        ("azp", "other-client"),
        ("scope", "other-scope"),
        ("iss", "https://other.example.test"),
        ("aud", "other"),
        ("jti", "changed"),
        ("iat", 0),
        ("exp", 0),
        ("tenant_id", "default"),
        ("tenant_id", ""),
    ],
)
def test_wrong_tenant_pair_rejects_other_differences(controlled_pair, field, value):
    controlled_pair({field: value})
    with pytest.raises(pytest.fail.Exception, match="differ only"):
        live._controlled_tokens()


def test_unrelated_baseline_identity_rejected(controlled_pair):
    controlled_pair(baseline_changes={"sub": "ungranted"})
    with pytest.raises(pytest.fail.Exception, match="authorized BFF identity"):
        live._controlled_tokens()


def test_mismatched_signing_header_rejected(controlled_pair):
    controlled_pair(wrong_headers={"kid": "different-key"})
    with pytest.raises(pytest.fail.Exception, match="differ only"):
        live._controlled_tokens()


def test_negative_probe_requires_successful_baseline(controlled_pair, monkeypatch):
    baseline, _ = controlled_pair()
    calls = []

    def response(payload, token, **kwargs):
        calls.append(token)
        return {
            "status": 200 if kwargs.get("health") else 401 if token is None else 404
        }

    monkeypatch.setattr(live, "_admission_response", response)
    with pytest.raises(
        pytest.fail.Exception, match="Baseline identity must be authorized"
    ):
        live.test_real_unauthorized_and_wrong_tenant_denied()
    assert calls == [None, None, baseline]


def test_negative_probe_uses_identical_dataset_and_request(
    controlled_pair, monkeypatch
):
    baseline, wrong = controlled_pair()
    calls = []

    def response(payload, token, **kwargs):
        calls.append((payload, token))
        return {
            "status": 200
            if kwargs.get("health") or token == baseline
            else 401
            if token is None
            else 404,
            "error": "dataset_not_found" if token == wrong else None,
        }

    monkeypatch.setattr(live, "_admission_response", response)
    live.test_real_unauthorized_and_wrong_tenant_denied()
    assert calls[-2][0] == calls[-1][0]
    assert calls[-2][1] == baseline and calls[-1][1] == wrong


def test_admission_probe_uses_laravel_config_and_stdin_not_operator_url(monkeypatch):
    from types import SimpleNamespace

    calls = []
    monkeypatch.setattr(
        live,
        "_bound_target",
        lambda: ("backend-id", "http://hawki_backend:80/v1/query"),
    )

    def run(command, **kwargs):
        calls.append((command, kwargs))
        return SimpleNamespace(
            returncode=0,
            stdout=json.dumps(
                {"status": 200, "target": "http://hawki_backend/v1/query"}
            ),
        )

    monkeypatch.setattr(live.subprocess, "run", run)
    live._admission_response({"dataset_id": "a"}, "SECRET-TOKEN")
    command, kwargs = calls[0]
    assert command[:6] == ["docker", "exec", "-i", "hawki_rag_app", "php", "-r"]
    assert "config('config.python_query_url')" in command[-1]
    assert "SECRET-TOKEN" not in " ".join(command)
    assert json.loads(kwargs["input"])["token"] == "SECRET-TOKEN"


def test_admission_probe_rejects_effective_destination_drift(monkeypatch):
    from types import SimpleNamespace

    monkeypatch.setattr(
        live,
        "_bound_target",
        lambda: ("backend-id", "http://hawki_backend:80/v1/query"),
    )
    monkeypatch.setattr(
        live.subprocess,
        "run",
        lambda *a, **k: SimpleNamespace(
            returncode=0,
            stdout=json.dumps({"status": 200, "target": "http://other/v1/query"}),
        ),
    )
    with pytest.raises(pytest.fail.Exception, match="destination changed"):
        live._admission_response({}, None)


def test_live_parity_mismatch_fails_without_assert_rewriting():
    python = {"count": 2, "hits": [], "kg": []}
    bridge = {"count": 1, "hits": [], "kg": []}
    with pytest.raises(pytest.fail.Exception):
        live._assert_hits_parity(python, bridge)
