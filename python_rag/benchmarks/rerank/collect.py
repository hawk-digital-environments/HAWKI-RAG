"""Development-only first-pass candidate capture; never calls reranking/generation."""

import argparse
from copy import deepcopy
from datetime import datetime, timezone
import json
from pathlib import Path


FORMAT = "hawki-rerank-evaluation-v1"


def capture_hits(hits: list[dict], limit: int) -> list[dict]:
    from hawki_bridge.adapters.reranker_documents import extract_rerank_documents
    from hawki_bridge.application.query.hits import hit_identity

    selected = hits[:limit]
    documents = extract_rerank_documents(selected)
    candidates = []
    for order, (hit, text) in enumerate(zip(selected, documents)):
        identity = hit_identity(hit)
        if not identity:
            raise ValueError("retrieved hit has no stable identity")
        candidates.append(
            {
                "id": identity,
                "original_id": hit.get("id"),
                "retrieval_order": order,
                "retrieval_score": hit.get("score"),
                "text": text,
                "metadata": deepcopy(hit.get("payload") or {}),
                "relevance": None,
            }
        )
    if len({c["id"] for c in candidates}) != len(candidates):
        raise ValueError("retrieval returned duplicate chunk identities")
    return candidates


def collect_one(request, query: dict, dependencies, limit: int = 50) -> dict:
    from hawki_rag_contracts.retrieval.query import QueryRequest
    from hawki_bridge.application.query.execution import (
        build_query_runtime,
        prepare_query,
        retrieve_query_hits,
    )

    if not 20 <= limit <= 50:
        raise ValueError("candidate limit must be between 20 and 50")
    configured = QueryRequest.model_validate(
        {
            **request.model_dump(),
            "query": query["query"],
            "top_k": limit,
            "generate": False,
            "reranker": "none",
        }
    )
    timings = {}
    runtime = build_query_runtime(
        configured,
        resolve_model_provider=dependencies.resolve_model_provider,
        vector_search_factory=dependencies.vector_search_factory,
    )
    prepared = prepare_query(configured, runtime, timings)
    hits = retrieve_query_hits(
        configured,
        runtime,
        prepared,
        graph_search=dependencies.graph_search,
        timings=timings,
    )
    # This is the first-pass input to expand_query_hits -> rerank_and_filter_hits.
    candidates = capture_hits(hits, limit)
    return {
        **query,
        "rerank_query": prepared.text,
        "judgment_status": "pending",
        "candidates": candidates,
        "available_candidates": len(hits),
        "below_target": len(candidates) < 20,
        "retrieval_timings_ms": timings,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--request", type=Path, required=True)
    parser.add_argument(
        "--queries", type=Path, default=Path(__file__).with_name("queries.json")
    )
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--limit", type=int, default=50)
    parser.add_argument("--execute", action="store_true")
    args = parser.parse_args()
    from hawki_rag_contracts.retrieval.query import QueryRequest

    request = QueryRequest.model_validate(json.loads(args.request.read_text()))
    queries = json.loads(args.queries.read_text())
    if not 20 <= args.limit <= 50 or not 30 <= len(queries) <= 50:
        parser.error("use 30–50 queries and a candidate limit of 20–50")
    if len({q["id"] for q in queries}) != len(queries):
        parser.error("query IDs must be unique")
    if args.output.exists():
        parser.error("output exists; choose a new snapshot path")
    if not args.execute:
        print(
            f"Validated saved request; {len(queries)} queries, up to {args.limit} candidates each. No retrieval executed."
        )
        return
    # The collector is an operator tool, not an authorization endpoint. Never
    # manufacture scope or choose collections by probing database contents.
    if (
        request.provider != "ollama"
        or "/" in request.authorized_scope.embedding_model
        and not request.authorized_scope.embedding_model.startswith(
            ("ollama/", "ollama_embed/")
        )
    ):
        parser.error(
            "live capture currently requires the authorized local Ollama embedding route"
        )
    from hawki_bridge.composition import build_query_dependencies

    dependencies = build_query_dependencies()
    rows = [collect_one(request, query, dependencies, args.limit) for query in queries]
    report = {
        "format": FORMAT,
        "private": True,
        "captured_at": datetime.now(timezone.utc).isoformat(),
        "authorized_scope": request.authorized_scope.model_dump(),
        "retrieval_configuration": {
            **request.model_dump(exclude={"query", "authorized_scope"}),
            "top_k": args.limit,
            "generate": False,
            "reranker": "none",
        },
        "candidate_limit": args.limit,
        "queries": rows,
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("x", encoding="utf-8") as stream:
        json.dump(report, stream, indent=2, ensure_ascii=False, allow_nan=False)
        stream.write("\n")
    print(
        f"Captured {len(rows)} queries; {sum(r['below_target'] for r in rows)} have fewer than 20 candidates."
    )


if __name__ == "__main__":
    main()
