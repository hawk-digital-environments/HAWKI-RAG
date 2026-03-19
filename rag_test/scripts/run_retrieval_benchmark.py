from __future__ import annotations

import argparse
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from rag_test.retrieval.backend_runtime import BackendRuntime
from rag_test.retrieval.evaluator import evaluate_queries
from rag_test.retrieval.qdrant_client_wrapper import QdrantClientWrapper
from rag_test.retrieval.utils import (
    csv_dump,
    create_run_id,
    json_dump,
    load_benchmark_config,
    load_gold_map,
    load_query_groups,
    resolve_collection_name,
    save_config_snapshot,
    setup_logger,
    validate_fairness_rules,
)


def build_parser() -> argparse.ArgumentParser:
    """Define CLI arguments for the retrieval benchmark phase."""
    parser = argparse.ArgumentParser(description="Run offline retrieval benchmark across enabled embedding models using the current python_rag query pipeline.")
    parser.add_argument("--run-id", help="Optional run id. Defaults to an auto-generated UTC timestamp.")
    parser.add_argument("--models", nargs="*", help="Optional subset of model keys to benchmark.")
    return parser


def main() -> int:
    """Execute the retrieval benchmark and write summary plus per-query artifacts."""
    bootstrap_logger = setup_logger(ROOT / "rag_test" / "logs" / "run_retrieval_benchmark.log", "rag_test.retrieval.bootstrap")
    bootstrap_logger.info("run_retrieval_benchmark.main start")
    try:
        args = build_parser().parse_args()
        config = load_benchmark_config()
        errors = validate_fairness_rules(config)
        if errors:
            for error in errors:
                print(f"FAIRNESS ERROR: {error}")
            return 1

        run_id = args.run_id or create_run_id("retrieval")
        run_dir = Path(config["paths"]["results"]) / run_id
        logger = setup_logger(run_dir / "logs" / "run.log", f"rag_test.retrieval.{run_id}")
        logger.info("run_retrieval_benchmark.main initialized run_id=%s", run_id)
        save_config_snapshot(config, run_dir)

        queries = load_query_groups(Path(config["paths"]["benchmark_queries"]))
        gold_map = load_gold_map(Path(config["paths"]["benchmark_gold"]) / "retrieval_gold.json", "query_id")
        selected_models = args.models or [
            key for key, model in config["models"].items() if model.get("enabled", False)
        ]
        logger.info("run_retrieval_benchmark.main selected_models=%s query_count=%s", selected_models, len(queries))
        runtime = BackendRuntime(config)
        qdrant = QdrantClientWrapper(
            base_url=config["qdrant"]["url"],
            api_key=config["qdrant"].get("api_key", ""),
            timeout_seconds=int(config["qdrant"]["timeout_seconds"]),
        )

        summary_rows = []
        per_query_rows = []
        for model_key in selected_models:
            logger.info("run_retrieval_benchmark.main model_start model_key=%s", model_key)
            try:
                collection_name = resolve_collection_name(config, model_key)
                artifacts = evaluate_queries(
                    queries=queries,
                    gold_map=gold_map,
                    runtime=runtime,
                    top_k=max(int(config["collections"]["top_k"]), 10),
                    model_key=model_key,
                    collection_name=collection_name,
                    phase="retrieval",
                )
                summary = dict(artifacts.summary_rows[0])
                summary["collection_points"] = qdrant.collection_count(collection_name)
                summary["distance"] = config["collections"]["distance"]
                summary["reranker_enabled"] = bool(config["reranker"]["enabled"])
                summary["reranker_mode"] = str(config["reranker"]["mode"])
                summary_rows.append(summary)
                per_query_rows.extend(artifacts.per_query_rows)
                logger.info("run_retrieval_benchmark.main model_success model_key=%s collection=%s", model_key, collection_name)
            except Exception as exc:
                logger.exception("run_retrieval_benchmark.main model_failed model_key=%s error=%s", model_key, exc)
                raise

        json_dump(run_dir / "retrieval" / "summary.json", {"models": summary_rows})
        csv_dump(run_dir / "retrieval" / "summary.csv", summary_rows)
        json_dump(run_dir / "retrieval" / "per_query.json", {"queries": per_query_rows})
        logger.info("run_retrieval_benchmark.main success run_id=%s", run_id)
        return 0
    except Exception as exc:
        bootstrap_logger.exception("run_retrieval_benchmark.main failed error=%s", exc)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
