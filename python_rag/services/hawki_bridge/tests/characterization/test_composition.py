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


@pytest.mark.parametrize("env", [{}, {"RERANKER_PROVIDER": "cohere"}])
def test_default_provider_keeps_legacy_function(env):
    dependencies = composition.build_query_dependencies(load_settings(env))
    assert dependencies.rerank_hits is composition.rerank_hits


@pytest.mark.parametrize(
    "env",
    [
        {"RERANKER_PROVIDER": "unsupported"},
        {"RERANKER_PROVIDER": "litellm"},
        {"RERANKER_PROVIDER": "litellm", "RERANKER_MODEL": "litellm_proxy/model"},
    ],
)
def test_incomplete_backend_configuration_fails_at_composition(env):
    with pytest.raises(ValueError):
        composition.build_query_dependencies(load_settings(env))


def test_gateway_key_is_not_in_settings_repr():
    settings = load_settings({"LITELLM_PROXY_API_KEY": "private-placeholder"})
    assert "private-placeholder" not in repr(settings)


@pytest.mark.parametrize(
    "model,base,override,expected",
    [
        (
            "litellm_proxy/jina-rerank",
            "https://ai.ixdlab.hawk.de",
            None,
            "litellm_proxy/jlu/jina-rerank",
        ),
        (
            "litellm_proxy/jina-rerank",
            "https://ai.ixdlab.hawk.de/v1/",
            None,
            "litellm_proxy/jlu/jina-rerank",
        ),
        ("litellm_proxy/jina-rerank", "https://ai.ixdlab.hawk.de", "none", None),
        ("litellm_proxy/jina-rerank", "https://ai.ixdlab.hawk.de", "", None),
        (
            "litellm_proxy/jina-rerank",
            "https://ai.ixdlab.hawk.de",
            "litellm_proxy/other",
            "litellm_proxy/other",
        ),
        ("litellm_proxy/other", "https://ai.ixdlab.hawk.de", None, None),
        ("litellm_proxy/jlu/jina-rerank", "https://ai.ixdlab.hawk.de", None, None),
        ("cohere/rerank-v3.5", "https://ai.ixdlab.hawk.de", None, None),
        ("litellm_proxy/jina-rerank", "https://other.invalid", None, None),
    ],
)
def test_ixdlab_fallback_selection_is_narrow(model, base, override, expected):
    env = {
        "RERANKER_PROVIDER": "litellm",
        "RERANKER_MODEL": model,
        "LITELLM_PROXY_API_BASE": base,
        "LITELLM_PROXY_API_KEY": "test-placeholder",
    }
    if override is not None:
        env["RERANKER_FALLBACK_MODEL"] = override
    deps = composition.build_query_dependencies(load_settings(env))
    client = deps.rerank_hits.keywords["reranker"]
    assert client.fallback_model == expected


@pytest.mark.parametrize(
    "override", ["cohere/other", "litellm_proxy/", "litellm_proxy/jina-rerank"]
)
def test_fallback_cannot_escape_gateway_or_repeat_primary(override):
    with pytest.raises(ValueError):
        composition.build_query_dependencies(
            load_settings(
                {
                    "RERANKER_PROVIDER": "litellm",
                    "RERANKER_MODEL": "litellm_proxy/jina-rerank",
                    "LITELLM_PROXY_API_BASE": "https://ai.ixdlab.hawk.de",
                    "LITELLM_PROXY_API_KEY": "test-placeholder",
                    "RERANKER_FALLBACK_MODEL": override,
                }
            )
        )
