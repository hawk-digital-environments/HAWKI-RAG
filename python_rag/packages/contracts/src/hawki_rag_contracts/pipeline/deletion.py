"""Immutable, control-plane-owned managed output deletion scope."""

from __future__ import annotations

from pydantic import BaseModel, ConfigDict, Field, model_validator


class DeletionTarget(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    output_id: int = Field(gt=0)
    doc_id: str = Field(min_length=1, max_length=191)
    source_id: str = Field(min_length=1, max_length=191)
    collection: str = Field(min_length=1, max_length=191)
    neo4j_namespace: str | None = Field(default=None, min_length=1, max_length=191)


class DeletionWriter(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    workflow_id: str = Field(min_length=1, max_length=255)
    run_id: str = Field(min_length=1, max_length=255)


class DeleteManagedDocumentInput(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    operation_id: str = Field(pattern=r"^delete_[0-9a-f]{64}$")
    managed_document_id: str = Field(min_length=1, max_length=191)
    dataset_id: str = Field(min_length=1, max_length=191)
    targets: list[DeletionTarget] = Field(max_length=1000)
    writers: list[DeletionWriter] = Field(default_factory=list, max_length=1000)
    task_queues: dict[str, str] = Field(default_factory=dict)

    @model_validator(mode="after")
    def unique_outputs(self) -> DeleteManagedDocumentInput:
        if len({target.output_id for target in self.targets}) != len(self.targets):
            raise ValueError("deletion outputs must be unique")
        return self


DELETE_MANAGED_DOCUMENT_WORKFLOW = "DeleteManagedDocumentWorkflow"
VERIFY_DELETION_WRITERS_ACTIVITY = "verify_managed_deletion_writers"
DELETE_MANAGED_VECTORS_ACTIVITY = "delete_managed_vectors"
DELETE_MANAGED_GRAPH_ACTIVITY = "delete_managed_graph"
REPORT_MANAGED_DELETION_ACTIVITY = "report_managed_deletion"
