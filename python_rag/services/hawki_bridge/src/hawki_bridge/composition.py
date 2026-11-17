"""Concrete query dependency composition for the bridge process."""

from functools import partial

from hawki_model_providers.factory import create_model_provider

from hawki_bridge.adapters.neo4j_reader import Neo4jReader
from hawki_bridge.adapters.qdrant_reader import QdrantReader
from hawki_bridge.adapters.reranker_client import rerank_hits
from hawki_bridge.application.dependencies import QueryDependencies
from hawki_bridge.settings import BridgeSettings, load_settings

# Providers that intentionally ride the Cohere rerank protocol in
# litellm.rerank dispatch; any other Cohere-family config is a fallback.
_RERANK_COHERE_DIALECT_PROVIDERS = frozenset(
    {"cohere", "litellm_proxy", "bedrock", "infinity", "together_ai"}
)


def _gateway_base(url: str) -> str:
    """Normalize a gateway base; LiteLLM appends /v1/rerank itself."""
    base = url.rstrip("/")
    return base[: -len("/v1")] if base.endswith("/v1") else base


def _validate_sdk_rerank_model(
    model: str, *, api_base: str | None = None, api_key: str | None = None
) -> None:
    """Reject RERANKER_MODEL values the pinned LiteLLM cannot rerank with.

    Pure resolution mirroring litellm.rerank dispatch; no network or keys.
    TODO(litellm#52252): simplify once the SDK rejects unsupported rerank
    providers itself.
    """
    from litellm.types.utils import LlmProviders
    from litellm.utils import ProviderConfigManager

    import litellm

    try:
        _, provider, _, _ = litellm.get_llm_provider(
            model=model, api_base=api_base, api_key=api_key
        )
        config = ProviderConfigManager.get_provider_rerank_config(
            model, LlmProviders(provider), api_base, []
        )
    except Exception as exc:
        raise ValueError(
            f"LiteLLM cannot route RERANKER_MODEL {model!r} for reranking"
            f" ({type(exc).__name__})"
        ) from exc
    if (
        isinstance(config, (litellm.CohereRerankConfig, litellm.CohereRerankV2Config))
        and provider not in _RERANK_COHERE_DIALECT_PROVIDERS
    ):
        raise ValueError(
            f"RERANKER_MODEL {model!r}: provider {provider!r} has no rerank"
            " support in the pinned LiteLLM"
        )


def _resolve_fallback_model(settings: BridgeSettings) -> str | None:
    """Resolve the explicit fallback alias; unset, blank or none disables it."""
    configured = settings.reranker_fallback_model
    if configured is None or not configured.strip() or configured.lower() == "none":
        return None
    if not settings.sdk_reranker_model.startswith("litellm_proxy/"):
        raise ValueError("RERANKER_FALLBACK_MODEL requires a litellm_proxy/ primary")
    if not configured.startswith("litellm_proxy/") or configured == "litellm_proxy/":
        raise ValueError("Fallback must use the same LiteLLM gateway")
    return configured


def build_query_dependencies(
    settings: BridgeSettings | None = None,
) -> QueryDependencies:
    """Bind query ports to the production model, storage, and reranker adapters."""

    active = settings or load_settings()
    rank = rerank_hits
    if active.reranker_provider == "local":
        from hawki_reranking.local_reranker import LocalReranker

        client = LocalReranker(active.local_reranker_model)
        rank = partial(rerank_hits, reranker=client)
    elif active.reranker_provider == "litellm":
        import litellm
        from hawki_reranking.litellm_reranker import LiteLLMReranker

        model = active.sdk_reranker_model
        prefix, _, _ = model.partition("/")
        options: dict[str, object] = {"timeout": 30, "num_retries": 0, "max_retries": 0}
        if prefix == "litellm_proxy":
            if not active.reranker_gateway_base or not active.reranker_gateway_key:
                raise ValueError(
                    "SDK gateway reranking requires a configured base and key"
                )
            options.update(
                api_base=_gateway_base(active.reranker_gateway_base),
                api_key=active.reranker_gateway_key,
            )
        _validate_sdk_rerank_model(
            model,
            api_base=options.get("api_base"),
            api_key=options.get("api_key"),
        )
        client = LiteLLMReranker(
            model,
            fallback_model=_resolve_fallback_model(active),
            rerank_fn=partial(litellm.rerank, **options),
        )
        rank = partial(rerank_hits, reranker=client)
    elif active.reranker_provider != "cohere":
        raise ValueError(
            "Unsupported RERANKER_PROVIDER; use cohere/legacy_http, local, or litellm"
        )

    return QueryDependencies(
        vector_search_factory=QdrantReader,
        graph_search=Neo4jReader(),
        resolve_model_provider=create_model_provider,
        rerank_hits=rank,
    )


__all__ = ["build_query_dependencies"]
