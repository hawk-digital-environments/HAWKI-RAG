"""Canonical model configuration resolution."""

from __future__ import annotations

import pytest

from hawki_model_providers.configuration import (
    OLLAMA_ROUTING_PREFIXES,
    ollama_api_base,
    resolve_provider_name_from_env,
    resolve_provider_selection_from_env,
    routing_prefix,
    strip_routing_prefix,
)


@pytest.fixture(autouse=True)
def _clean_provider_env(monkeypatch: pytest.MonkeyPatch) -> None:
    for name in (
        "RAG_PROVIDER",
        "EMBED_MODEL",
        "CHAT_MODEL",
        "VISION_MODEL",
    ):
        monkeypatch.delenv(name, raising=False)


def test_provider_name_is_required_and_fails_loud() -> None:
    with pytest.raises(ValueError, match="RAG_PROVIDER"):
        resolve_provider_name_from_env()


def test_provider_name_reads_the_explicit_switch(monkeypatch) -> None:
    monkeypatch.setenv("RAG_PROVIDER", " LiteLLM ")

    assert resolve_provider_name_from_env() == "litellm"


def test_provider_name_does_not_infer_from_litellm_urls(monkeypatch) -> None:
    # Deployments keep LITELLM_API_URL set while running the direct
    # Ollama provider, so presence of that key must never switch or imply
    # the provider: without RAG_PROVIDER the failure is loud.
    monkeypatch.setenv("LITELLM_API_URL", "http://litellm:4000/v1")

    with pytest.raises(ValueError, match="RAG_PROVIDER"):
        resolve_provider_name_from_env()


def test_selection_resolves_the_canonical_keys(monkeypatch) -> None:
    monkeypatch.setenv("EMBED_MODEL", "ollama/bge-m3")
    monkeypatch.setenv("CHAT_MODEL", "ollama_chat/llama3.1:8b")
    monkeypatch.setenv("VISION_MODEL", "ollama_chat/qwen2.5vl:7b")

    selection = resolve_provider_selection_from_env()

    assert selection.embedding_model == "ollama/bge-m3"
    assert selection.chat_model == "ollama_chat/llama3.1:8b"
    assert selection.vision_model == "ollama_chat/qwen2.5vl:7b"


def test_selection_accepts_bare_model_names(monkeypatch) -> None:
    monkeypatch.setenv("EMBED_MODEL", "bge-m3")
    monkeypatch.setenv("CHAT_MODEL", "gpt-4.1-mini")

    selection = resolve_provider_selection_from_env()

    assert selection.embedding_model == "bge-m3"
    assert selection.chat_model == "gpt-4.1-mini"
    assert selection.vision_model is None


def test_selection_ignores_blank_values(monkeypatch) -> None:
    monkeypatch.setenv("EMBED_MODEL", "  ")

    assert resolve_provider_selection_from_env().embedding_model is None


def test_strip_routing_prefix_translates_canonical_values() -> None:
    assert strip_routing_prefix("ollama/bge-m3") == "bge-m3"
    assert strip_routing_prefix("ollama_chat/llama3.1:8b") == "llama3.1:8b"
    assert strip_routing_prefix("openai/gpt-4.1-mini") == "gpt-4.1-mini"
    assert strip_routing_prefix("bge-m3") == "bge-m3"
    assert strip_routing_prefix("bge-m3") == "bge-m3"
    assert strip_routing_prefix(None) is None
    assert strip_routing_prefix("") is None


def test_routing_prefix_extracts_the_provider_segment() -> None:
    assert routing_prefix("ollama_chat/llama3.1:8b") == "ollama_chat"
    assert routing_prefix("openai/gpt-4.1-mini") == "openai"
    assert routing_prefix("gpt-4.1-mini") == ""
    assert routing_prefix("") == ""


def test_ollama_prefixes_are_a_subset_of_the_stripped_routing_prefixes() -> None:
    assert OLLAMA_ROUTING_PREFIXES <= {
        "ollama",
        "ollama_chat",
        "ollama_embed",
        "openai",
        "anthropic",
        "gemini",
        "vertex_ai",
        "azure",
    }


def test_ollama_api_base_strips_the_native_suffix(monkeypatch) -> None:
    monkeypatch.delenv("OLLAMA_API_URL", raising=False)

    assert ollama_api_base("http://hawki_ollama:11434/api") == (
        "http://hawki_ollama:11434"
    )
    assert ollama_api_base("http://hawki_ollama:11434") == "http://hawki_ollama:11434"
    assert ollama_api_base("") == "http://ollama:11434"
    assert ollama_api_base(None) == "http://ollama:11434"


def test_ollama_api_base_reads_the_environment_when_no_url_is_passed(
    monkeypatch,
) -> None:
    monkeypatch.setenv("OLLAMA_API_URL", "http://ollama.internal:11434/api")

    assert ollama_api_base() == "http://ollama.internal:11434"
    assert ollama_api_base("http://explicit:11434/api") == "http://explicit:11434"
