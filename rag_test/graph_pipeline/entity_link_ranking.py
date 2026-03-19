from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any

from rag_test.retrieval.metrics import mean, recall_at_k, reciprocal_rank

from .utils import score_candidates

logger = logging.getLogger(__name__)


@dataclass(slots=True)
class EntityLinkArtifacts:
    """Container for one model's entity-link benchmark outputs."""
    summary_rows: list[dict[str, Any]]
    per_case_rows: list[dict[str, Any]]


def evaluate_entity_link_cases(
    *,
    cases: list[dict[str, Any]],
    embedder: Any,
    model_key: str,
    k_values: list[int],
) -> EntityLinkArtifacts:
    """Evaluate mention-to-entity candidate ranking for one embedding model."""
    logger.info(
        "entity_link.evaluate_entity_link_cases start model_key=%s cases=%s k_values=%s",
        model_key,
        len(cases),
        k_values,
    )
    per_case_rows: list[dict[str, Any]] = []
    recalls_by_k: dict[int, list[float]] = {k: [] for k in k_values}
    mrrs: list[float] = []
    top1s: list[float] = []

    for case in cases:
        case_id = case["id"]
        logger.info("entity_link.evaluate_entity_link_cases case_start model_key=%s case_id=%s", model_key, case_id)
        try:
            mention_text = case["mention_text"]
            candidates = case["candidates"]
            candidate_texts = [candidate["text"] for candidate in candidates]
            vectors = embedder.embed_texts([mention_text, *candidate_texts])
            query_vector = vectors[0]
            candidate_vectors = {
                candidate["id"]: vectors[index + 1]
                for index, candidate in enumerate(candidates)
            }
            ranked = score_candidates(query_vector, candidate_vectors)
            ranked_ids = [candidate_id for candidate_id, _score in ranked]
            relevant_ids = {case["correct_entity_id"]}

            row = {
                "case_id": case_id,
                "model_key": model_key,
                "correct_entity_id": case["correct_entity_id"],
                "ranked_ids": ranked_ids,
                "mrr": reciprocal_rank(relevant_ids, ranked_ids, max(k_values)),
                "top1_accuracy": 1.0 if ranked_ids and ranked_ids[0] == case["correct_entity_id"] else 0.0,
            }
            for k in k_values:
                metric_key = f"recall_at_{k}"
                row[metric_key] = recall_at_k(relevant_ids, ranked_ids, k)
                recalls_by_k[k].append(row[metric_key])

            mrrs.append(row["mrr"])
            top1s.append(row["top1_accuracy"])
            per_case_rows.append(row)
            logger.info("entity_link.evaluate_entity_link_cases case_success model_key=%s case_id=%s", model_key, case_id)
        except Exception as exc:
            logger.exception("entity_link.evaluate_entity_link_cases case_failed model_key=%s case_id=%s error=%s", model_key, case_id, exc)
            per_case_rows.append({"case_id": case_id, "model_key": model_key, "error": str(exc)})

    summary_row = {
        "model_key": model_key,
        "case_count": len(per_case_rows),
        "mrr": round(mean(mrrs), 6),
        "top1_accuracy": round(mean(top1s), 6),
    }
    for k in k_values:
        summary_row[f"recall_at_{k}"] = round(mean(recalls_by_k[k]), 6)

    logger.info("entity_link.evaluate_entity_link_cases success model_key=%s processed_cases=%s", model_key, len(per_case_rows))
    return EntityLinkArtifacts(summary_rows=[summary_row], per_case_rows=per_case_rows)
