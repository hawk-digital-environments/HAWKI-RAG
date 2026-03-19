from __future__ import annotations

import argparse
import json
import logging
from pathlib import Path
import sys
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from rag_test.retrieval.utils import csv_dump, load_benchmark_config

logger = logging.getLogger(__name__)


def build_parser() -> argparse.ArgumentParser:
    """Define CLI arguments for exporting one run's benchmark summaries."""
    parser = argparse.ArgumentParser(description="Export one run's benchmark summaries into a flat report.")
    parser.add_argument("--run-id", required=True, help="Run id under rag_test/results.")
    return parser


def load_json(path: Path) -> dict[str, Any]:
    """Load one result JSON file while treating a missing file as an empty payload."""
    logger.info("export_results.load_json start path=%s", path)
    try:
        if not path.is_file():
            logger.warning("export_results.load_json missing path=%s", path)
            return {}
        payload = json.loads(path.read_text(encoding="utf-8"))
        logger.info("export_results.load_json success path=%s", path)
        return payload
    except Exception as exc:
        logger.exception("export_results.load_json failed path=%s error=%s", path, exc)
        raise


def best_model(rows: list[dict[str, Any]], metric_key: str) -> dict[str, Any] | None:
    """Select the best-scoring model row for one metric key."""
    logger.info("export_results.best_model start metric_key=%s rows=%s", metric_key, len(rows))
    try:
        if not rows:
            logger.info("export_results.best_model no rows metric_key=%s", metric_key)
            return None
        winner = max(rows, key=lambda row: float(row.get(metric_key, 0.0)))
        logger.info("export_results.best_model success metric_key=%s winner=%s", metric_key, winner.get("model_key"))
        return winner
    except Exception as exc:
        logger.exception("export_results.best_model failed metric_key=%s error=%s", metric_key, exc)
        raise


def main() -> int:
    """Flatten benchmark outputs into export-friendly CSV and summary JSON files."""
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logger.info("export_results.main start")
    try:
        args = build_parser().parse_args()
        config = load_benchmark_config()
        run_dir = Path(config["paths"]["results"]) / args.run_id
        logger.info("export_results.main run_dir=%s", run_dir)
        retrieval_summary = load_json(run_dir / "retrieval" / "summary.json").get("models", [])
        entity_summary = load_json(run_dir / "graph" / "entity_link" / "summary.json").get("models", [])
        neighbor_summary = load_json(run_dir / "graph" / "neighbor_evidence" / "summary.json").get("models", [])

        rows = []
        for category, summary in [
            ("retrieval", retrieval_summary),
            ("graph_entity_link", entity_summary),
            ("graph_neighbor_evidence", neighbor_summary),
        ]:
            logger.info("export_results.main flatten_category category=%s rows=%s", category, len(summary))
            for row in summary:
                flattened = {"category": category}
                flattened.update(row)
                rows.append(flattened)

        csv_dump(run_dir / "export_summary.csv", rows)

        winners = {
            "retrieval_ndcg_at_10": best_model(retrieval_summary, "ndcg_at_10"),
            "retrieval_mrr_at_10": best_model(retrieval_summary, "mrr_at_10"),
            "entity_link_mrr": best_model(entity_summary, "mrr"),
            "neighbor_evidence_mrr": best_model(neighbor_summary, "mrr"),
        }
        (run_dir / "export_summary.json").write_text(json.dumps(winners, indent=2), encoding="utf-8")
        logger.info("export_results.main wrote_export_files run_dir=%s", run_dir)

        for label, winner in winners.items():
            if winner:
                print(f"{label}: {winner['model_key']}")
        logger.info("export_results.main success")
        return 0
    except Exception as exc:
        logger.exception("export_results.main failed error=%s", exc)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
