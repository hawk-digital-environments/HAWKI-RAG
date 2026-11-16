"""Concrete query dependency composition for the bridge process."""

from functools import partial

from hawki_model_providers.factory import create_model_provider

from hawki_bridge.adapters.neo4j_reader import Neo4jReader
from hawki_bridge.adapters.qdrant_reader import QdrantReader
from hawki_bridge.adapters.reranker_client import rerank_hits
from hawki_bridge.application.dependencies import QueryDependencies
from hawki_bridge.settings import BridgeSettings, load_settings


def _gateway_base(url: str) -> str:
    """Normalize a gateway base; LiteLLM appends /v1/rerank itself."""
    base = url.rstrip("/")
    return base[: -len("/v1")] if base.endswith("/v1") else base


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

        options: dict[str, object] = {"timeout": 30, "num_retries": 0, "max_retries": 0}
        if active.sdk_reranker_model.startswith("litellm_proxy/"):
            if not active.reranker_gateway_base or not active.reranker_gateway_key:
                raise ValueError(
                    "SDK gateway reranking requires a configured base and key"
                )
            options.update(
                api_base=_gateway_base(active.reranker_gateway_base),
                api_key=active.reranker_gateway_key,
            )
        fallback_model = None
        if (
            active.sdk_reranker_model == "litellm_proxy/jina-rerank"
            and active.reranker_gateway_base.rstrip("/")
            in {"https://ai.ixdlab.hawk.de", "https://ai.ixdlab.hawk.de/v1"}
        ):
            configured = active.reranker_fallback_model
            fallback_model = (
                "litellm_proxy/jlu/jina-rerank" if configured is None else configured
            )
            if not fallback_model or fallback_model.lower() == "none":
                fallback_model = None
            elif (
                not fallback_model.startswith("litellm_proxy/")
                or fallback_model == "litellm_proxy/"
            ):
                raise ValueError("Fallback must use the same LiteLLM gateway")
        client = LiteLLMReranker(
            active.sdk_reranker_model,
            fallback_model=fallback_model,
            rerank_fn=partial(litellm.rerank, **options),
        )
        rank = partial(rerank_hits, reranker=client)
    elif active.reranker_provider != "cohere":
        raise ValueError("Unsupported RERANKER_PROVIDER; use cohere, local, or litellm")

    return QueryDependencies(
        vector_search_factory=QdrantReader,
        graph_search=Neo4jReader(),
        resolve_model_provider=create_model_provider,
        rerank_hits=rank,
    )


__all__ = ["build_query_dependencies"]
