"""Deterministic tests of live-suite admission guards; no live requests run."""

import json

import pytest

from hawki_backend.query.settings import load_query_settings

from . import test_live_scope_integration as live
from .doubles import load_fixture


def test_missing_live_input_fails_instead_of_skipping(monkeypatch):
    monkeypatch.delenv("HAWKI_PARITY_INVENTORY_FILE", raising=False)
    with pytest.raises(pytest.fail.Exception):
        live._configured_datasets()


def test_live_request_uses_deployed_reranker_and_models(monkeypatch):
    # Golden data is used only in this deterministic helper test, never by live mode.
    env = load_fixture()["registry"]["environ"].copy()
    env["HAWKI_BACKEND_QUERY_RERANKER"] = "external"
    env["HAWKI_BACKEND_QUERY_RERANKER_MIX_WEIGHT"] = "0.3"
    settings = load_query_settings(env)
    monkeypatch.setattr(live, "_deployed_settings", lambda: settings)
    dataset_id = next(iter(settings.datasets))
    request = live._trusted_request(dataset_id, fast_mode=True)
    assert request["generate"] is False
    assert request["reranker"] == "external"
    assert request["mix_weight"] == 0.3
    scope = settings.resolve_scope(dataset_id)
    assert (
        request["chat_model"]
        == settings.models_for(scope.embedding_provider).chat_model
    )
    assert request["authorized_scope"]["graph_enabled"] is False


def test_real_python_probe_calls_cli_without_mock_auth_or_token_output(monkeypatch):
    from types import SimpleNamespace

    commands = []

    def run(command, **kwargs):
        commands.append(command)
        return SimpleNamespace(
            returncode=0, stdout=json.dumps({"status": 200, "payload": {"ok": True}})
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
