from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from rag_test.retrieval.metrics import mean
from rag_test.retrieval.utils import cosine_similarity


def load_cases(path: Path, key: str = "cases") -> list[dict[str, Any]]:
    """Load graph benchmark cases from one gold JSON file."""
    payload = json.loads(path.read_text(encoding="utf-8"))
    return payload.get(key, [])


def summarize_metric_rows(rows: list[dict[str, Any]], metric_keys: list[str]) -> dict[str, float]:
    """Aggregate selected metrics across graph benchmark case rows."""
    return {metric_key: round(mean(row.get(metric_key, 0.0) for row in rows), 6) for metric_key in metric_keys}


def score_candidates(query_vector: list[float], candidate_vectors: dict[str, list[float]]) -> list[tuple[str, float]]:
    """Rank graph candidates by cosine similarity to the query-side embedding."""
    ranked = [
        (candidate_id, cosine_similarity(query_vector, vector))
        for candidate_id, vector in candidate_vectors.items()
    ]
    ranked.sort(key=lambda item: item[1], reverse=True)
    return ranked
