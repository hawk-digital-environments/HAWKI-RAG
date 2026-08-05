"""HTTP translations for bridge dependency resolution."""

from fastapi import HTTPException

from hawki_model_providers.ports import ModelProvider, ProviderResolver


def get_provider_or_400(service: ProviderResolver, name: str) -> ModelProvider:
    try:
        return service.get_provider(name)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


__all__ = ["get_provider_or_400"]
