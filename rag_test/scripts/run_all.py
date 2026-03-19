from __future__ import annotations

import argparse
import logging
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from rag_test.retrieval.utils import create_run_id, project_root

logger = logging.getLogger(__name__)

def build_parser() -> argparse.ArgumentParser:
    """Define CLI arguments for the full multi-phase benchmark flow."""
    parser = argparse.ArgumentParser(description="Run the full rag_test offline benchmark flow.")
    parser.add_argument("--prepare-data", action="store_true", help="Also copy data before building collections.")
    parser.add_argument("--run-id", help="Optional shared run id for retrieval and graph phases.")
    return parser


def run_step(script_name: str, extra_args: list[str]) -> None:
    """Execute one benchmark script as a subprocess and surface failures clearly."""
    logger.info("run_all.run_step start script=%s args=%s", script_name, extra_args)
    root = project_root()
    script_path = root / "scripts" / script_name
    command = [sys.executable, str(script_path), *extra_args]
    try:
        subprocess.run(command, cwd=str(root.parent), check=True)
        logger.info("run_all.run_step success script=%s", script_name)
    except Exception as exc:
        logger.exception("run_all.run_step failed script=%s error=%s", script_name, exc)
        raise


def main() -> int:
    """Run the complete benchmark sequence from validation through export."""
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logger.info("run_all.main start")
    try:
        args = build_parser().parse_args()
        run_id = args.run_id or create_run_id("benchmark")
        logger.info("run_all.main resolved run_id=%s prepare_data=%s", run_id, args.prepare_data)
        if args.prepare_data:
            run_step("prepare_test_data.py", [])
        run_step("validate_fairness.py", [])
        run_step("build_qdrant_collections.py", [])
        run_step("run_retrieval_benchmark.py", ["--run-id", run_id])
        run_step("run_graph_benchmark.py", ["--run-id", run_id])
        run_step("export_results.py", ["--run-id", run_id])
        logger.info("run_all.main success run_id=%s", run_id)
        return 0
    except Exception as exc:
        logger.exception("run_all.main failed error=%s", exc)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
