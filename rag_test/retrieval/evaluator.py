from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from typing import Any

from .metrics import mean, ndcg_at_k, recall_at_k, reciprocal_rank

logger = logging.getLogger(__name__)


@dataclass(slots=True)
class RetrievalRunArtifacts:
    """Container for aggregated and per-query retrieval benchmark outputs."""
    summary_rows: list[dict[str, Any]]
    per_query_rows: list[dict[str, Any]]


def evaluate_queries(
    *,
    queries: list[dict[str, Any]],
    gold_map: dict[str, dict[str, Any]],
    runtime: Any,
    top_k: int,
    model_key: str,
    collection_name: str,
    phase: str = "retrieval",
) -> RetrievalRunArtifacts:
    """Run the retrieval benchmark query set for one model through the live backend."""
    logger.info(
        "evaluator.evaluate_queries start model_key=%s phase=%s queries=%s collection=%s top_k=%s",
        model_key,
        phase,
        len(queries),
        collection_name,
        top_k,
    )
    per_query_rows: list[dict[str, Any]] = []

    recalls_5: list[float] = []
    recalls_10: list[float] = []
    mrrs_10: list[float] = []
    ndcgs_10: list[float] = []
    latencies_ms: list[float] = []

    for query in queries:
        query_id = query["id"]
        logger.info("evaluator.evaluate_queries query_start model_key=%s query_id=%s", model_key, query_id)
        try:
            gold = gold_map.get(query_id, {})
            relevant_ids = set(gold.get("relevant_doc_ids", []))
            graded = {key: float(value) for key, value in gold.get("graded_relevance", {}).items()}

            started = time.perf_counter()
            payload = runtime.run_query(
                model_key=model_key,
                query_text=query["text"],
                top_k=top_k,
                fast_mode=(phase == "retrieval"),
                smart_lookup=(phase != "retrieval"),
            )
            hits = payload.get("hits", [])
            latency_ms = round((time.perf_counter() - started) * 1000.0, 3)
            latencies_ms.append(latency_ms)

            ranked_doc_ids = []
            seen_doc_ids: set[str] = set()
            for hit in hits:
                doc_id = str(hit.get("payload", {}).get("doc_id", ""))
                if not doc_id or doc_id in seen_doc_ids:
                    continue
                seen_doc_ids.add(doc_id)
                ranked_doc_ids.append(doc_id)

            metrics = {
                "recall_at_5": recall_at_k(relevant_ids, ranked_doc_ids, 5),
                "recall_at_10": recall_at_k(relevant_ids, ranked_doc_ids, 10),
                "mrr_at_10": reciprocal_rank(relevant_ids, ranked_doc_ids, 10),
                "ndcg_at_10": ndcg_at_k(graded, ranked_doc_ids, 10),
            }
            recalls_5.append(metrics["recall_at_5"])
            recalls_10.append(metrics["recall_at_10"])
            mrrs_10.append(metrics["mrr_at_10"])
            ndcgs_10.append(metrics["ndcg_at_10"])

            per_query_rows.append(
                {
                    "query_id": query_id,
                    "group": query.get("group", ""),
                    "language": query.get("language", ""),
                    "model_key": model_key,
                    "collection_name": collection_name,
                    "phase": phase,
                    "latency_ms": latency_ms,
                    "relevant_doc_ids": sorted(relevant_ids),
                    "ranked_doc_ids": ranked_doc_ids,
                    "backend_retrieval": payload.get("retrieval", {}),
                    **metrics,
                }
            )
            logger.info(
                "evaluator.evaluate_queries query_success model_key=%s query_id=%s hits=%s latency_ms=%s",
                model_key,
                query_id,
                len(ranked_doc_ids),
                latency_ms,
            )
        except Exception as exc:
            logger.exception(
                "evaluator.evaluate_queries query_failed model_key=%s query_id=%s error=%s",
                model_key,
                query_id,
                exc,
            )
            per_query_rows.append(
                {
                    "query_id": query_id,
                    "group": query.get("group", ""),
                    "language": query.get("language", ""),
                    "model_key": model_key,
                    "collection_name": collection_name,
                    "phase": phase,
                    "latency_ms": None,
                    "relevant_doc_ids": sorted(set(gold_map.get(query_id, {}).get("relevant_doc_ids", []))),
                    "ranked_doc_ids": [],
                    "backend_retrieval": {},
                    "error": str(exc),
                    "recall_at_5": 0.0,
                    "recall_at_10": 0.0,
                    "mrr_at_10": 0.0,
                    "ndcg_at_10": 0.0,
                }
            )

    summary_rows = [
        {
            "model_key": model_key,
            "collection_name": collection_name,
            "query_count": len(per_query_rows),
            "recall_at_5": round(mean(recalls_5), 6),
            "recall_at_10": round(mean(recalls_10), 6),
            "mrr_at_10": round(mean(mrrs_10), 6),
            "ndcg_at_10": round(mean(ndcgs_10), 6),
            "avg_latency_ms": round(mean(latencies_ms), 3),
        }
    ]
    logger.info("evaluator.evaluate_queries success model_key=%s processed_queries=%s", model_key, len(per_query_rows))
    return RetrievalRunArtifacts(summary_rows=summary_rows, per_query_rows=per_query_rows)
