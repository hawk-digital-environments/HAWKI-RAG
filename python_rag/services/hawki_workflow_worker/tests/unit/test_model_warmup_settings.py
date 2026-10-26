"""Model warmup settings resolution and schedule bootstrap values."""

from __future__ import annotations

import pytest

from hawki_workflow_worker.settings import (
    ModelWarmupSettings,
    WorkflowWorkerSettings,
)
from hawki_workflow_worker.startup import (
    build_warmup_schedule,
    build_warmup_workflow_input,
)


def _clear_warmup_env(monkeypatch: pytest.MonkeyPatch) -> None:
    for name in (
        "MODEL_WARMUP_ENABLED",
        "MODEL_WARMUP_INCLUDE_VISION",
        "MODEL_WARMUP_SCHEDULE_CRON",
        "MODEL_WARMUP_SCHEDULE_TZ",
        "MODEL_WARMUP_QUERY",
        "RAG_DEFAULT_PROVIDER",
        "EMBED_MODEL",
        "CHAT_MODEL",
        "VISION_MODEL",
    ):
        monkeypatch.delenv(name, raising=False)


def test_settings_default_to_daily_schedule_with_canonical_models(
    monkeypatch,
) -> None:
    _clear_warmup_env(monkeypatch)
    monkeypatch.setenv("EMBED_MODEL", "ollama/bge-m3")
    monkeypatch.setenv("CHAT_MODEL", "ollama_chat/llama3.1:8b")
    monkeypatch.setenv("VISION_MODEL", "ollama_chat/qwen2.5vl:7b")

    settings = ModelWarmupSettings.from_environment()

    assert settings.enabled is True
    assert settings.provider == "ollama"
    assert settings.schedule_cron == "0 6 * * *"
    assert settings.schedule_timezone == "Europe/Berlin"
    assert settings.query == "Reply with OK."
    assert settings.models == (
        {"name": "ollama/bge-m3", "kind": "embed"},
        {"name": "ollama_chat/llama3.1:8b", "kind": "chat"},
        {"name": "ollama_chat/qwen2.5vl:7b", "kind": "vision"},
    )


def test_settings_follow_litellm_configuration(monkeypatch) -> None:
    _clear_warmup_env(monkeypatch)
    monkeypatch.setenv("RAG_DEFAULT_PROVIDER", "litellm")
    monkeypatch.setenv("EMBED_MODEL", "hawki-ollama-embedding")
    monkeypatch.setenv("CHAT_MODEL", "hawki-gpt-chat")

    settings = ModelWarmupSettings.from_environment()

    assert settings.provider == "litellm"
    assert settings.models == (
        {"name": "hawki-ollama-embedding", "kind": "embed"},
        {"name": "hawki-gpt-chat", "kind": "chat"},
    )


def test_settings_can_disable_vision_and_the_warmup(monkeypatch) -> None:
    _clear_warmup_env(monkeypatch)
    monkeypatch.setenv("EMBED_MODEL", "ollama/bge-m3")
    monkeypatch.setenv("VISION_MODEL", "ollama_chat/qwen2.5vl:7b")
    monkeypatch.setenv("MODEL_WARMUP_INCLUDE_VISION", "false")
    monkeypatch.setenv("MODEL_WARMUP_ENABLED", "no")

    settings = ModelWarmupSettings.from_environment()

    assert settings.enabled is False
    assert [model["kind"] for model in settings.models] == ["embed"]


def test_workflow_input_carries_provider_query_and_models(monkeypatch) -> None:
    _clear_warmup_env(monkeypatch)
    monkeypatch.setenv("EMBED_MODEL", "ollama/bge-m3")

    warmup = ModelWarmupSettings.from_environment()

    assert build_warmup_workflow_input(warmup) == {
        "provider": "ollama",
        "query": "Reply with OK.",
        "models": [{"name": "ollama/bge-m3", "kind": "embed"}],
    }


def test_schedule_spec_uses_cron_and_timezone(monkeypatch) -> None:
    _clear_warmup_env(monkeypatch)
    monkeypatch.setenv("EMBED_MODEL", "ollama/bge-m3")
    monkeypatch.setenv("MODEL_WARMUP_SCHEDULE_CRON", "30 5 * * *")
    monkeypatch.setenv("MODEL_WARMUP_SCHEDULE_TZ", "UTC")

    worker = WorkflowWorkerSettings(
        temporal_address="temporal:7233",
        temporal_namespace="default",
        workflow_task_queue="rag-workflow-task-queue",
    )
    schedule = build_warmup_schedule(
        worker, ModelWarmupSettings.from_environment()
    )

    assert schedule.spec.cron_expressions == ["30 5 * * *"]
    assert schedule.spec.time_zone_name == "UTC"
    assert schedule.action.workflow == "ModelWarmupWorkflow"
    assert schedule.action.task_queue == "rag-workflow-task-queue"
