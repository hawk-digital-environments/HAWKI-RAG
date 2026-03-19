from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any

from rag_test.retrieval.metrics import mean, recall_at_k, reciprocal_rank

from .utils import score_candidates

logger = logging.getLogger(__name__)


@dataclass(slots=True)
class NeighborEvidenceArtifacts:
    """Container for one model's neighbor-evidence benchmark outputs."""
    summary_rows: list[dict[str, Any]]
    per_case_rows: list[dict[str, Any]]


def evaluate_neighbor_evidence_cases(
    *,
    cases: list[dict[str, Any]],
    embedder: Any,
    model_key: str,
    k_values: list[int],
    seed_k: int = 3,
) -> NeighborEvidenceArtifacts:
    """Evaluate graph-expanded evidence retrieval for one embedding model."""
    logger.info(
        "neighbor_evidence.evaluate_neighbor_evidence_cases start model_key=%s cases=%s k_values=%s seed_k=%s",
        model_key,
        len(cases),
        k_values,
        seed_k,
    )
    per_case_rows: list[dict[str, Any]] = []
    recalls_by_k: dict[int, list[float]] = {k: [] for k in k_values}
    seed_recalls: list[float] = []
    mrrs: list[float] = []
    top1s: list[float] = []

    for case in cases:
        case_id = case["id"]
        logger.info("neighbor_evidence.evaluate_neighbor_evidence_cases case_start model_key=%s case_id=%s", model_key, case_id)
        try:
            query_text = case["query"]
            seed_candidates = case["seed_candidates"]
            evidence_candidates = {item["id"]: item["text"] for item in case["evidence_candidates"]}
            texts = [query_text]
            texts.extend(item["text"] for item in seed_candidates)
            texts.extend(evidence_candidates.values())
            vectors = embedder.embed_texts(texts)

            offset = 1
            seed_vectors = {}
            for item in seed_candidates:
                seed_vectors[item["id"]] = vectors[offset]
                offset += 1

            evidence_vectors = {}
            for evidence_id in evidence_candidates:
                evidence_vectors[evidence_id] = vectors[offset]
                offset += 1

            query_vector = vectors[0]
            ranked_seeds = score_candidates(query_vector, seed_vectors)
            ranked_seed_ids = [candidate_id for candidate_id, _score in ranked_seeds]

            selected_seed_ids = ranked_seed_ids[:seed_k]
            expanded_evidence_ids: list[str] = []
            for seed in seed_candidates:
                if seed["id"] in selected_seed_ids:
                    expanded_evidence_ids.extend(seed.get("neighbor_evidence_ids", []))

            expanded_evidence_ids = list(dict.fromkeys(expanded_evidence_ids))
            if not expanded_evidence_ids:
                expanded_evidence_ids = list(evidence_candidates.keys())

            ranked_evidence = score_candidates(
                query_vector,
                {candidate_id: evidence_vectors[candidate_id] for candidate_id in expanded_evidence_ids if candidate_id in evidence_vectors},
            )
            ranked_evidence_ids = [candidate_id for candidate_id, _score in ranked_evidence]

            relevant_seed_ids = set(case.get("relevant_seed_ids", []))
            relevant_evidence_ids = set(case.get("relevant_evidence_ids", []))
            seed_recall = recall_at_k(relevant_seed_ids, ranked_seed_ids, seed_k)
            seed_recalls.append(seed_recall)

            row = {
                "case_id": case_id,
                "model_key": model_key,
                "seed_k": seed_k,
                "selected_seed_ids": selected_seed_ids,
                "ranked_evidence_ids": ranked_evidence_ids,
                "seed_recall_at_k": seed_recall,
                "mrr": reciprocal_rank(relevant_evidence_ids, ranked_evidence_ids, max(k_values)),
                "top1_accuracy": 1.0 if ranked_evidence_ids and ranked_evidence_ids[0] in relevant_evidence_ids else 0.0,
            }
            for k in k_values:
                metric_key = f"recall_at_{k}"
                row[metric_key] = recall_at_k(relevant_evidence_ids, ranked_evidence_ids, k)
                recalls_by_k[k].append(row[metric_key])

            mrrs.append(row["mrr"])
            top1s.append(row["top1_accuracy"])
            per_case_rows.append(row)
            logger.info("neighbor_evidence.evaluate_neighbor_evidence_cases case_success model_key=%s case_id=%s", model_key, case_id)
        except Exception as exc:
            logger.exception("neighbor_evidence.evaluate_neighbor_evidence_cases case_failed model_key=%s case_id=%s error=%s", model_key, case_id, exc)
            per_case_rows.append({"case_id": case_id, "model_key": model_key, "error": str(exc)})

    summary_row = {
        "model_key": model_key,
        "case_count": len(per_case_rows),
        "seed_recall_at_k": round(mean(seed_recalls), 6),
        "mrr": round(mean(mrrs), 6),
        "top1_accuracy": round(mean(top1s), 6),
    }
    for k in k_values:
        summary_row[f"recall_at_{k}"] = round(mean(recalls_by_k[k]), 6)

    logger.info("neighbor_evidence.evaluate_neighbor_evidence_cases success model_key=%s processed_cases=%s", model_key, len(per_case_rows))
    return NeighborEvidenceArtifacts(summary_rows=[summary_row], per_case_rows=per_case_rows)
