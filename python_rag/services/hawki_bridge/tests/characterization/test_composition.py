"""Bridge composition characterization at provider and reranker boundaries."""

from __future__ import annotations

import unittest
from unittest.mock import patch

import pytest

from hawki_bridge import composition
from hawki_bridge.settings import load_settings


class BridgeCompositionCharacterizationTests(unittest.TestCase):
    def test_bridge_composition_binds_provider_and_reranker_boundaries(
        self,
    ) -> None:
        from hawki_bridge import composition

        provider = {"provider": "toy-provider"}
        ranked = [{"id": "ranked"}]
        graph_search = object()
        vector_factory = object()
        with (
            patch.object(
                composition,
                "create_model_provider",
                return_value=provider,
            ) as provider_factory,
            patch.object(composition, "rerank_hits", return_value=ranked) as reranker,
            patch.object(composition, "Neo4jReader", return_value=graph_search),
            patch.object(composition, "QdrantReader", vector_factory),
        ):
            dependencies = composition.build_query_dependencies()
            selected_provider = dependencies.resolve_model_provider("toy-provider")
            result = dependencies.rerank_hits(
                hits=[{"id": "raw"}],
                user_query="toy",
                provider=provider,
                query_vector=None,
                mode="cosine",
                top_n=1,
                mix_mode=False,
                mix_weight=0.5,
            )

        self.assertIs(selected_provider, provider)
        self.assertIs(result, ranked)
        self.assertIs(dependencies.vector_search_factory, vector_factory)
        self.assertIs(dependencies.graph_search, graph_search)
        provider_factory.assert_called_once_with("toy-provider")
        self.assertEqual(reranker.call_args.kwargs["top_n"], 1)


@pytest.mark.parametrize(
    "env",
    [
        {},
        {"RERANKER_PROVIDER": "cohere"},
        {"RERANKER_PROVIDER": "legacy_http"},
        {"RERANKER_PROVIDER": "LEGACY_HTTP"},
    ],
)
def test_default_provider_keeps_legacy_function(env):
    dependencies = composition.build_query_dependencies(load_settings(env))
    assert dependencies.rerank_hits is composition.rerank_hits


@pytest.mark.parametrize(
    "env",
    [
        {"RERANKER_PROVIDER": "unsupported"},
        {"RERANKER_PROVIDER": "litellm"},
        {"RERANKER_PROVIDER": "litellm", "RERANKER_MODEL": "litellm_proxy/model"},
        {
            "RERANKER_PROVIDER": "litellm",
            "RERANKER_MODEL": "local/mixedbread-ai/mxbai-rerank-base-v1",
        },
        {"RERANKER_PROVIDER": "litellm", "RERANKER_MODEL": "jina-rerank"},
        {"RERANKER_PROVIDER": "litellm", "RERANKER_MODEL": "ollama/mxbai-rerank"},
    ],
)
def test_incomplete_backend_configuration_fails_at_composition(env):
    with pytest.raises(ValueError):
        composition.build_query_dependencies(load_settings(env))


def test_hosted_sdk_model_without_gateway_composes():
    env = {"RERANKER_PROVIDER": "litellm", "RERANKER_MODEL": "voyage/rerank-2.5"}
    deps = composition.build_query_dependencies(load_settings(env))
    client = deps.rerank_hits.keywords["reranker"]
    assert client.model == "voyage/rerank-2.5"
    assert client.fallback_model is None


def test_gateway_key_is_not_in_settings_repr():
    settings = load_settings({"LITELLM_PROXY_API_KEY": "private-placeholder"})
    assert "private-placeholder" not in repr(settings)


@pytest.mark.parametrize(
    "base",
    [
        "https://gateway.invalid",
        "https://gateway.invalid/",
        "https://gateway.invalid/v1",
        "https://gateway.invalid/v1/",
    ],
)
def test_gateway_base_is_normalized_for_the_sdk_transport(base):
    env = {
        "RERANKER_PROVIDER": "litellm",
        "RERANKER_MODEL": "litellm_proxy/jina-rerank",
        "LITELLM_PROXY_API_BASE": base,
        "LITELLM_PROXY_API_KEY": "test-placeholder",
    }
    deps = composition.build_query_dependencies(load_settings(env))
    client = deps.rerank_hits.keywords["reranker"]
    # LiteLLM appends /v1/rerank itself; a preconfigured /v1 must not double.
    assert client._rerank_fn.keywords["api_base"] == "https://gateway.invalid"


@pytest.mark.parametrize(
    "model, override, expected",
    [
        ("litellm_proxy/jina-rerank", None, None),
        ("litellm_proxy/jina-rerank", "", None),
        ("litellm_proxy/jina-rerank", "none", None),
        ("litellm_proxy/jina-rerank", "NONE", None),
        (
            "litellm_proxy/jina-rerank",
            "litellm_proxy/jlu/jina-rerank",
            "litellm_proxy/jlu/jina-rerank",
        ),
        (
            "litellm_proxy/other",
            "litellm_proxy/jlu/jina-rerank",
            "litellm_proxy/jlu/jina-rerank",
        ),
        ("cohere/rerank-v3.5", None, None),
    ],
)
def test_fallback_model_is_explicit_configuration(model, override, expected):
    env = {
        "RERANKER_PROVIDER": "litellm",
        "RERANKER_MODEL": model,
        "LITELLM_PROXY_API_KEY": "test-placeholder",
    }
    if model.startswith("litellm_proxy/"):
        env["LITELLM_PROXY_API_BASE"] = "https://gateway.invalid"
    if override is not None:
        env["RERANKER_FALLBACK_MODEL"] = override
    deps = composition.build_query_dependencies(load_settings(env))
    client = deps.rerank_hits.keywords["reranker"]
    assert client.fallback_model == expected


@pytest.mark.parametrize(
    ("model", "override"),
    [
        ("litellm_proxy/jina-rerank", "cohere/other"),
        ("litellm_proxy/jina-rerank", "litellm_proxy/"),
        ("litellm_proxy/jina-rerank", "litellm_proxy/jina-rerank"),
        ("cohere/rerank-v3.5", "litellm_proxy/jlu/jina-rerank"),
    ],
)
def test_fallback_cannot_escape_gateway_or_repeat_primary(model, override):
    with pytest.raises(ValueError):
        composition.build_query_dependencies(
            load_settings(
                {
                    "RERANKER_PROVIDER": "litellm",
                    "RERANKER_MODEL": model,
                    "LITELLM_PROXY_API_BASE": "https://gateway.invalid",
                    "LITELLM_PROXY_API_KEY": "test-placeholder",
                    "RERANKER_FALLBACK_MODEL": override,
                }
            )
        )
