"""Apply validated request-level model aliases to a provider instance."""

from __future__ import annotations


def apply_provider_overrides(provider: object, body: object) -> None:
    """Apply server-selected model aliases without changing provider credentials."""

    if provider is None:
        return

    embedding_model = getattr(body, "embedding_model", None)
    authorized_scope = getattr(body, "authorized_scope", None)
    if authorized_scope is not None:
        embedding_model = getattr(authorized_scope, "embedding_model", None) or embedding_model
    if embedding_model and hasattr(provider, "embed_model"):
        embedding_model_value = str(embedding_model).strip()
        current_embedding_model = str(getattr(provider, "embed_model", "") or "").strip()
        if current_embedding_model != embedding_model_value and hasattr(provider, "_last_embed_dim"):
            provider._last_embed_dim = None
        provider.embed_model = embedding_model_value

    chat_model = getattr(body, "chat_model", None) or getattr(body, "graph_model", None)
    if chat_model and hasattr(provider, "rag_model"):
        chat_model_value = str(chat_model).strip()
        provider.rag_model = chat_model_value
        try:
            setattr(provider, "_explicit_graph_model", chat_model_value)
        except Exception:
            pass

    vision_model = getattr(body, "vision_model", None)
    if vision_model and hasattr(provider, "vision_model"):
        vision_model_value = str(vision_model).strip()
        provider.vision_model = vision_model_value
        try:
            setattr(provider, "_explicit_vision_model", vision_model_value)
        except Exception:
            pass


__all__ = ["apply_provider_overrides"]
