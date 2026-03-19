from __future__ import annotations

import argparse
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from rag_test.graph_pipeline.entity_link_ranking import evaluate_entity_link_cases
from rag_test.graph_pipeline.neighbor_evidence_retrieval import evaluate_neighbor_evidence_cases
from rag_test.graph_pipeline.utils import load_cases
from rag_test.retrieval.backend_runtime import BackendRuntime
from rag_test.retrieval.embedder import Embedder
from rag_test.retrieval.utils import (
    csv_dump,
    create_run_id,
    json_dump,
    load_benchmark_config,
    save_config_snapshot,
    setup_logger,
    validate_fairness_rules,
)


def build_parser() -> argparse.ArgumentParser:
    """Define CLI arguments for the graph-support benchmark phase."""
    parser = argparse.ArgumentParser(description="Run graph-support benchmark for aligned embedding models.")
    parser.add_argument("--run-id", help="Optional run id. Defaults to an auto-generated UTC timestamp.")
    parser.add_argument("--models", nargs="*", help="Optional subset of model keys to benchmark.")
    return parser


def main() -> int:
    """Execute entity-link and neighbor-evidence graph benchmarks for each model."""
    bootstrap_logger = setup_logger(ROOT / "rag_test" / "logs" / "run_graph_benchmark.log", "rag_test.graph.bootstrap")
    bootstrap_logger.info("run_graph_benchmark.main start")
    try:
        args = build_parser().parse_args()
        config = load_benchmark_config()
        errors = validate_fairness_rules(config)
        if errors:
            for error in errors:
                print(f"FAIRNESS ERROR: {error}")
            return 1

        run_id = args.run_id or create_run_id("graph")
        run_dir = Path(config["paths"]["results"]) / run_id
        logger = setup_logger(run_dir / "logs" / "run.log", f"rag_test.graph.{run_id}")
        logger.info("run_graph_benchmark.main initialized run_id=%s", run_id)
        save_config_snapshot(config, run_dir)

        entity_cases = load_cases(Path(config["paths"]["benchmark_gold"]) / "entity_link_gold.json")
        neighbor_cases = load_cases(Path(config["paths"]["benchmark_gold"]) / "neighbor_evidence_gold.json")
        k_values = [int(value) for value in config["benchmark"]["graph_k_values"]]
        selected_models = args.models or [
            key for key, model in config["models"].items() if model.get("enabled", False)
        ]
        logger.info("run_graph_benchmark.main selected_models=%s", selected_models)

        entity_summary_rows = []
        entity_case_rows = []
        neighbor_summary_rows = []
        neighbor_case_rows = []
        runtime = BackendRuntime(config)

        for model_key in selected_models:
            logger.info("run_graph_benchmark.main model_start model_key=%s", model_key)
            try:
                model = config["models"][model_key]
                embedder = Embedder(model_key=model_key, model_config=model, runtime=runtime)
                entity_artifacts = evaluate_entity_link_cases(
                    cases=entity_cases,
                    embedder=embedder,
                    model_key=model_key,
                    k_values=k_values,
                )
                entity_summary_rows.extend(entity_artifacts.summary_rows)
                entity_case_rows.extend(entity_artifacts.per_case_rows)

                neighbor_artifacts = evaluate_neighbor_evidence_cases(
                    cases=neighbor_cases,
                    embedder=embedder,
                    model_key=model_key,
                    k_values=k_values,
                )
                neighbor_summary_rows.extend(neighbor_artifacts.summary_rows)
                neighbor_case_rows.extend(neighbor_artifacts.per_case_rows)
                logger.info("run_graph_benchmark.main model_success model_key=%s", model_key)
            except Exception as exc:
                logger.exception("run_graph_benchmark.main model_failed model_key=%s error=%s", model_key, exc)
                raise

        json_dump(run_dir / "graph" / "entity_link" / "summary.json", {"models": entity_summary_rows})
        csv_dump(run_dir / "graph" / "entity_link" / "summary.csv", entity_summary_rows)
        json_dump(run_dir / "graph" / "entity_link" / "per_case.json", {"cases": entity_case_rows})

        json_dump(run_dir / "graph" / "neighbor_evidence" / "summary.json", {"models": neighbor_summary_rows})
        csv_dump(run_dir / "graph" / "neighbor_evidence" / "summary.csv", neighbor_summary_rows)
        json_dump(run_dir / "graph" / "neighbor_evidence" / "per_case.json", {"cases": neighbor_case_rows})
        logger.info("run_graph_benchmark.main success run_id=%s", run_id)
        return 0
    except Exception as exc:
        bootstrap_logger.exception("run_graph_benchmark.main failed error=%s", exc)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
