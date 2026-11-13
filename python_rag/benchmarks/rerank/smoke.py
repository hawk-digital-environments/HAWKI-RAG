"""One-query smoke checks using the benchmark adapters and model configuration."""

import argparse
import json
import os
from pathlib import Path
from time import perf_counter

from benchmark import (
    ROOT,
    load_gateway_environment,
    make_reranker,
    map_results,
    select_models,
    skip_reason,
)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--model", choices=("bge", "jina"), required=True)
    parser.add_argument("--allow-remote", action="store_true")
    parser.add_argument("--allow-download", action="store_true")
    parser.add_argument("--env-file", type=Path)
    args = parser.parse_args()
    # Load only the two explicitly allowed gateway variables, never display values.
    if args.env_file:
        load_gateway_environment(args.env_file)
    os.environ["LITELLM_LOCAL_MODEL_COST_MAP"] = "True"
    spec = select_models(json.loads((ROOT / "models.json").read_text()), [args.model])[
        0
    ]
    reason = skip_reason(spec, True, args.allow_remote, dict(os.environ))
    if reason:
        print(json.dumps({"model": args.model, "status": "skipped", "reason": reason}))
        return
    candidates = [
        {
            "id": "vector",
            "payload": {"content": "Dataset deletion removes owned Qdrant points."},
        },
        {"id": "weather", "payload": {"content": "The weather in Germany is sunny."}},
        {
            "id": "graph",
            "payload": {
                "content": "Neo4j graph contributions are cleaned up during dataset deletion."
            },
        },
    ]
    from hawki_bridge.adapters.reranker_documents import extract_rerank_documents

    documents = extract_rerank_documents(candidates)
    original = documents.copy()
    stage = "initialization"
    started = perf_counter()
    try:
        adapter, initialization = make_reranker(spec, args.allow_download)
        stage = "inference"
        started = perf_counter()
        response = adapter.rerank("How does HAWKI-RAG delete datasets?", documents, 3)
        elapsed = perf_counter() - started
        ranking = map_results(response, candidates)
        assert ranking[0]["index"] != 1, "weather document ranked first"
        assert documents == original
        print(
            json.dumps(
                {
                    "model": args.model,
                    "status": "passed",
                    "initialization_seconds": initialization,
                    "inference_seconds": elapsed,
                    "ranking": ranking,
                }
            )
        )
    except Exception as exc:
        # Do not print SDK exception messages: these can include request headers.
        print(
            json.dumps(
                {
                    "model": args.model,
                    "status": "failed",
                    "stage": stage,
                    "error_type": type(exc).__name__,
                    "http_status": getattr(exc, "status_code", None),
                    "elapsed_seconds": perf_counter() - started,
                }
            )
        )
        raise SystemExit(1) from None


if __name__ == "__main__":
    main()
