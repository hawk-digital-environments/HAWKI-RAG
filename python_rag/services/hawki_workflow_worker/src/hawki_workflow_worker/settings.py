"""Environment-backed settings read only by the workflow worker process."""

from __future__ import annotations

from dataclasses import dataclass
import os

from hawki_model_providers.configuration import (
    resolve_provider_name_from_env,
    resolve_provider_selection_from_env,
)
from hawki_rag_contracts.pipeline.temporal import WORKFLOW_TASK_QUEUE


def _environment_value(name: str, default: str) -> str:
    value = os.environ.get(name)
    if isinstance(value, str) and value.strip():
        return value.strip()
    return default


def _environment_flag(name: str, default: bool) -> bool:
    value = os.environ.get(name)
    if isinstance(value, str) and value.strip():
        return value.strip().lower() in {"1", "true", "yes", "on"}
    return default


@dataclass(frozen=True, slots=True)
class WorkflowWorkerSettings:
    """The only runtime settings required by the workflow worker."""

    temporal_address: str
    temporal_namespace: str
    workflow_task_queue: str

    @classmethod
    def from_environment(cls) -> "WorkflowWorkerSettings":
        """Load settings at process startup, outside workflow execution."""

        return cls(
            temporal_address=_environment_value("TEMPORAL_ADDRESS", "temporal:7233"),
            temporal_namespace=_environment_value("TEMPORAL_NAMESPACE", "default"),
            workflow_task_queue=_environment_value(
                "TEMPORAL_RAG_WORKFLOW_TASK_QUEUE",
                WORKFLOW_TASK_QUEUE,
            ),
        )


@dataclass(frozen=True, slots=True)
class ModelWarmupSettings:
    """Configuration for the startup/daily model warmup workflow.

    Provider and model names resolve through ``hawki_model_providers`` so
    the warmup follows the stack's provider configuration (Ollama today, a
    LiteLLM backend tomorrow) instead of a hand-maintained model list.
    """

    enabled: bool
    provider: str
    schedule_cron: str
    schedule_timezone: str
    query: str
    models: tuple[dict[str, str], ...]

    @classmethod
    def from_environment(cls) -> "ModelWarmupSettings":
        """Resolve warmup targets from the shared provider configuration."""

        include_vision = _environment_flag("MODEL_WARMUP_INCLUDE_VISION", True)
        provider = resolve_provider_name_from_env()
        selection = resolve_provider_selection_from_env()

        models: list[dict[str, str]] = []
        if selection.embedding_model:
            models.append({"name": selection.embedding_model, "kind": "embed"})
        if selection.chat_model:
            models.append({"name": selection.chat_model, "kind": "chat"})
        if selection.vision_model and include_vision:
            models.append({"name": selection.vision_model, "kind": "vision"})

        return cls(
            enabled=_environment_flag("MODEL_WARMUP_ENABLED", True),
            provider=provider,
            schedule_cron=_environment_value(
                "MODEL_WARMUP_SCHEDULE_CRON", "0 6 * * *"
            ),
            schedule_timezone=_environment_value(
                "MODEL_WARMUP_SCHEDULE_TZ", "Europe/Berlin"
            ),
            query=_environment_value("MODEL_WARMUP_QUERY", "Reply with OK."),
            models=tuple(models),
        )


__all__ = ["ModelWarmupSettings", "WorkflowWorkerSettings"]
