"""Canonical model configuration resolution."""

from __future__ import annotations

import pytest

from hawki_model_providers.configuration import (
    resolve_provider_name_from_env,
    resolve_provider_selection_from_env,
    strip_routing_prefix,
)


@pytest.fixture(autouse=True)
def _clean_provider_env(monkeypatch: pytest.MonkeyPatch) -> None:
    for name in (
        "RAG_DEFAULT_PROVIDER",
        "EMBED_MODEL",
        "CHAT_MODEL",
        "VISION_MODEL",
    ):
        monkeypatch.delenv(name, raising=False)


def test_provider_name_defaults_to_ollama() -> None:
    assert resolve_provider_name_from_env() == "ollama"


def test_provider_name_reads_the_existing_switch(monkeypatch) -> None:
    monkeypatch.setenv("RAG_DEFAULT_PROVIDER", " LiteLLM ")

    assert resolve_provider_name_from_env() == "litellm"


def test_provider_name_does_not_infer_from_litellm_urls(monkeypatch) -> None:
    # Deployments keep LITELLM_API_URL set while running the direct
    # Ollama provider, so presence of that key must not switch providers.
    monkeypatch.setenv("LITELLM_API_URL", "http://litellm:4000/v1")

    assert resolve_provider_name_from_env() == "ollama"


def test_selection_resolves_the_canonical_keys(monkeypatch) -> None:
    monkeypatch.setenv("EMBED_MODEL", "ollama/bge-m3")
    monkeypatch.setenv("CHAT_MODEL", "ollama_chat/llama3.1:8b")
    monkeypatch.setenv("VISION_MODEL", "ollama_chat/qwen2.5vl:7b")

    selection = resolve_provider_selection_from_env()

    assert selection.embedding_model == "ollama/bge-m3"
    assert selection.chat_model == "ollama_chat/llama3.1:8b"
    assert selection.vision_model == "ollama_chat/qwen2.5vl:7b"


def test_selection_accepts_bare_proxy_aliases(monkeypatch) -> None:
    monkeypatch.setenv("EMBED_MODEL", "hawki-ollama-embedding")
    monkeypatch.setenv("CHAT_MODEL", "hawki-gpt-chat")

    selection = resolve_provider_selection_from_env()

    assert selection.embedding_model == "hawki-ollama-embedding"
    assert selection.chat_model == "hawki-gpt-chat"
    assert selection.vision_model is None


def test_selection_ignores_blank_values(monkeypatch) -> None:
    monkeypatch.setenv("EMBED_MODEL", "  ")

    assert resolve_provider_selection_from_env().embedding_model is None


def test_strip_routing_prefix_translates_canonical_values() -> None:
    assert strip_routing_prefix("ollama/bge-m3") == "bge-m3"
    assert strip_routing_prefix("ollama_chat/llama3.1:8b") == "llama3.1:8b"
    assert strip_routing_prefix("openai/gpt-4.1-mini") == "gpt-4.1-mini"
    assert strip_routing_prefix("bge-m3") == "bge-m3"
    assert strip_routing_prefix("hawki-ollama-embedding") == "hawki-ollama-embedding"
    assert strip_routing_prefix(None) is None
    assert strip_routing_prefix("") is None
