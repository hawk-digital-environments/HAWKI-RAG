"""Benchmark domain results, distinct from native SDK transport responses."""

from pydantic import BaseModel, ConfigDict, Field, FiniteFloat


class EvaluationMetrics(BaseModel):
    model_config = ConfigDict(populate_by_name=True, frozen=True)

    ndcg: float = Field(alias="ndcg@10", ge=0, le=1, allow_inf_nan=False)
    reciprocal_rank: float = Field(alias="mrr@10", ge=0, le=1, allow_inf_nan=False)
    recall: float = Field(alias="recall@10", ge=0, le=1, allow_inf_nan=False)


class RankedResult(BaseModel):
    model_config = ConfigDict(frozen=True, strict=True)

    index: int = Field(ge=0)
    id: str
    score: FiniteFloat
