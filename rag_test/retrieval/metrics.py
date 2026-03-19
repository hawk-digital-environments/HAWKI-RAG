from __future__ import annotations

import math
from typing import Iterable


def recall_at_k(relevant_ids: set[str], ranked_ids: list[str], k: int) -> float:
    """Return the fraction of gold ids that appear within the top-k ranked ids."""
    if not relevant_ids:
        return 0.0
    retrieved = set(ranked_ids[:k])
    return len(relevant_ids & retrieved) / len(relevant_ids)


def reciprocal_rank(relevant_ids: set[str], ranked_ids: list[str], k: int) -> float:
    """Return reciprocal rank of the first relevant result within the top-k window."""
    for index, doc_id in enumerate(ranked_ids[:k], start=1):
        if doc_id in relevant_ids:
            return 1.0 / index
    return 0.0


def dcg_at_k(graded_relevance: dict[str, float], ranked_ids: list[str], k: int) -> float:
    """Compute discounted cumulative gain for graded relevance labels at cutoff k."""
    score = 0.0
    for index, doc_id in enumerate(ranked_ids[:k], start=1):
        gain = graded_relevance.get(doc_id, 0.0)
        if gain <= 0:
            continue
        score += (2 ** gain - 1) / math.log2(index + 1)
    return score


def ndcg_at_k(graded_relevance: dict[str, float], ranked_ids: list[str], k: int) -> float:
    """Normalize DCG by the ideal ranking so models are comparable across queries."""
    ideal_ids = [doc_id for doc_id, _gain in sorted(graded_relevance.items(), key=lambda item: item[1], reverse=True)]
    ideal = dcg_at_k(graded_relevance, ideal_ids, k)
    if ideal == 0:
        return 0.0
    return dcg_at_k(graded_relevance, ranked_ids, k) / ideal


def mean(values: Iterable[float]) -> float:
    """Return a safe mean that falls back to zero for empty iterables."""
    values = list(values)
    return sum(values) / len(values) if values else 0.0
