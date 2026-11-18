"""Public logical input; physical scope is never accepted."""

from pydantic import BaseModel, ConfigDict, Field
from typing import Literal


class ExpandBody(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)
    dataset_id: str = Field(min_length=1, max_length=191)
    node_id: str = Field(min_length=1, max_length=4096)
    depth: int = 1
    limit: int = Field(default=80, ge=1, le=250)


class ClearBody(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)
    dataset_id: str = Field(min_length=1, max_length=191)
    confirm: Literal["CLEAR_DATASET_GRAPH"]


class ClearReceipt(BaseModel):
    model_config = ConfigDict(extra="forbid")
    dataset_id: str = Field(min_length=1, max_length=191)
    operation_id: str = Field(min_length=32, max_length=32)
    success: bool
    deleted_nodes: int = Field(default=0, ge=0)
    deleted_relationships: int = Field(default=0, ge=0)
