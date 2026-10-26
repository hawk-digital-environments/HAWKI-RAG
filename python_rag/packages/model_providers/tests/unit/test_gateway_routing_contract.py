"""Gateway routing validated against litellm's own provider parser.

Mocked ``litellm.embedding``/``litellm.completion`` tests can pin a routed
model string that the real SDK rejects before transport ("LLM Provider NOT
provided"). These tests use the installed litellm's actual
``get_llm_provider`` parser as the oracle, so every string ``_route`` can
produce is checked for acceptance without any network call — and the
gateway deployment names are checked against the rendered proxy config.
"""

from __future__ import annotations

import pytest
from litellm import get_llm_provider

from hawki_model_providers import gateway_config
from hawki_model_providers.litellm_sdk import LiteLLMSDKProvider

_CANONICAL_MODELS = (
    "bge-m3",
    "gpt-4.1-mini",
    "ollama/bge-m3",
    "ollama_chat/llama3.1:8b",
    "openai/gpt-4.1-mini",
    "anthropic/claude-sonnet-4-5-20250929",
)


def _routed(provider_name: str, model: str, *, chat: bool) -> str:
    provider = LiteLLMSDKProvider(provider_name)
    return provider._route(model, chat=chat)


def test_every_routed_model_string_passes_litellms_parser() -> None:
    routed = [_routed("litellm", model, chat=True) for model in _CANONICAL_MODELS] + [
        _routed("ollama", model, chat=flag)
        for model in _CANONICAL_MODELS
        for flag in (True, False)
    ]

    for model in routed:
        # Raises BadRequestError ("LLM Provider NOT provided") on a name
        # the real SDK cannot route.
        get_llm_provider(model)


def test_bare_model_names_are_what_the_parser_rejects() -> None:
    # Canary documenting why the gateway path needs the openai/ transport
    # wrapper: without it, bare names that are not famous hosted models
    # die client-side.
    with pytest.raises(Exception, match="LLM Provider NOT provided"):
        get_llm_provider("bge-m3")


def test_openai_transport_strips_only_the_wrapper_prefix() -> None:
    # The parser consumes the first segment as the transport; everything
    # after it reaches the proxy verbatim as the deployment name.
    model, provider, _, _ = get_llm_provider("openai/ollama_chat/llama3.1:8b")
    assert provider == "openai"
    assert model == "ollama_chat/llama3.1:8b"
    model, provider, _, _ = get_llm_provider("openai/openai/gpt-4.1-mini")
    assert provider == "openai"
    assert model == "openai/gpt-4.1-mini"


def test_gateway_call_strings_resolve_to_rendered_deployments() -> None:
    """Every deployment name the client can send must exist in the config.

    The gateway renders its ``model_list`` from the same canonical keys the
    client routes on, so the two sides cannot drift: for each canonical
    model, the string the proxy receives after the transport prefix is
    stripped must be a rendered route name. Bare names only resolve when
    they are explicitly allowlisted; anything else fails with the proxy's
    model-not-found (no alias fallback).
    """

    env = {
        "EMBED_MODEL": "ollama/bge-m3",
        "CHAT_MODEL": "ollama_chat/llama3.1:8b",
        "VISION_MODEL": "ollama_chat/qwen2.5vl:7b",
        "EMBED_MODELS": "ollama/bge-m3,openai/text-embedding-3-small",
        "CHAT_MODELS": (
            "ollama_chat/llama3.1:8b,openai/gpt-4.1-mini,"
            "anthropic/claude-sonnet-4-5-20250929"
        ),
        "VISION_MODELS": "ollama_chat/qwen2.5vl:7b,openai/gpt-4.1-mini",
        "OLLAMA_API_URL": "http://hawki_ollama:11434/api",
    }
    deployed = {route["name"] for route in gateway_config.build_routes(env)}

    for model in (
        "ollama/bge-m3",
        "ollama_chat/llama3.1:8b",
        "ollama_chat/qwen2.5vl:7b",
        "openai/text-embedding-3-small",
        "openai/gpt-4.1-mini",
        "anthropic/claude-sonnet-4-5-20250929",
    ):
        routed = _routed("litellm", model, chat=True)
        assert routed.startswith("openai/")
        deployment_name = routed[len("openai/") :]
        assert deployment_name in deployed, deployment_name
