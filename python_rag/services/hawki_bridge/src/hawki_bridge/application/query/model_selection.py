"""Translate an authorized query into explicit provider model selection."""

from __future__ import annotations

from hawki_model_providers.configuration import (
    ProviderModelSelection,
    configure_provider_models,
)
from hawki_rag_contracts.retrieval.query import QueryRequest

from hawki_bridge.domain.ports import ModelProvider


def apply_query_models(provider: ModelProvider, request: QueryRequest) -> None:
    """Apply validated query model choices to the existing provider instance.

    The embedding model comes from the authorized dataset scope; chat and vision
    models come from the validated request. Provider identity and credentials
    remain unchanged.
    """

    configure_provider_models(
        provider,
        ProviderModelSelection(
            embedding_model=request.authorized_scope.embedding_model,
            chat_model=request.chat_model,
            vision_model=request.vision_model,
        ),
    )


__all__ = ["apply_query_models"]
