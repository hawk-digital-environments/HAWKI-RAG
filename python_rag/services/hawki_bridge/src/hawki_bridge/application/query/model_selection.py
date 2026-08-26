"""Translate an authorized query into explicit provider model selection."""

from __future__ import annotations

from hawki_model_providers.configuration import (
    ProviderModelSelection,
    configure_provider_models,
)
from hawki_rag_contracts.query import QueryRequest


def configure_query_provider(provider: object, request: QueryRequest) -> None:
    """Apply only the model aliases selected by the authorized request."""

    configure_provider_models(
        provider,
        ProviderModelSelection(
            embedding_model=(
                request.authorized_scope.embedding_model or request.embedding_model
            ),
            chat_model=request.chat_model or request.graph_model,
            vision_model=request.vision_model,
        ),
    )


__all__ = ["configure_query_provider"]
