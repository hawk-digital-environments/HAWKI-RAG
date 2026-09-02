"""Bridge-only HTTP request models."""

from __future__ import annotations

from pydantic import BaseModel, ConfigDict, Field, model_validator

from hawki_rag_contracts.retrieval.auth_scope import AuthorizedQueryScope
from hawki_rag_contracts.pipeline.ingestion import IngestSourceWorkflowInput
from hawki_rag_contracts.retrieval.query import QueryRequest

from hawki_bridge.settings import BridgeSettings


def apply_query_defaults(body: QueryRequest, settings: BridgeSettings) -> QueryRequest:
    """Fill omitted reranker values without replacing explicit request values."""

    updates: dict[str, object] = {}
    if "reranker" not in body.model_fields_set:
        updates["reranker"] = settings.reranker_mode
    if "mix_mode" not in body.model_fields_set:
        updates["mix_mode"] = settings.reranker_mix_mode
    if "mix_weight" not in body.model_fields_set:
        updates["mix_weight"] = settings.reranker_mix_weight
    return body.model_copy(update=updates)


class GraphReadRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    authorized_scope: AuthorizedQueryScope
    terms: list[str] = Field(min_length=1, max_length=50)
    limit: int = Field(default=30, ge=1, le=250)

    @model_validator(mode="after")
    def require_graph_scope(self) -> GraphReadRequest:
        if not self.authorized_scope.graph_enabled:
            raise ValueError("graph access is not enabled for the authorized dataset")
        return self


class StartIngestWorkflowRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    workflow_id: str = Field(min_length=1, max_length=255)
    workflow_input: IngestSourceWorkflowInput


class UpsertIngestScheduleRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    schedule_id: str = Field(min_length=1, max_length=255)
    workflow_id: str = Field(min_length=1, max_length=255)
    cadence: str
    workflow_input: IngestSourceWorkflowInput


class DeleteScheduleRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    schedule_id: str = Field(min_length=1, max_length=255)


class CancelWorkflowRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    workflow_id: str = Field(min_length=1, max_length=255)
    run_id: str | None = Field(default=None, max_length=255)


__all__ = [
    "CancelWorkflowRequest",
    "DeleteScheduleRequest",
    "GraphReadRequest",
    "QueryRequest",
    "StartIngestWorkflowRequest",
    "UpsertIngestScheduleRequest",
    "apply_query_defaults",
]
