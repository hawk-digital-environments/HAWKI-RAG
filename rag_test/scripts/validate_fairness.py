from __future__ import annotations

import logging
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from rag_test.retrieval.utils import load_benchmark_config, validate_fairness_rules

logger = logging.getLogger(__name__)


def main() -> int:
    """Fail fast if the configured benchmark would violate fairness constraints."""
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logger.info("validate_fairness.main start")
    try:
        config = load_benchmark_config()
        errors = validate_fairness_rules(config)
        if errors:
            for error in errors:
                print(f"FAIRNESS ERROR: {error}")
            logger.error("validate_fairness.main failed errors=%s", len(errors))
            return 1

        print("Fairness validation passed.")
        logger.info("validate_fairness.main success")
        return 0
    except Exception as exc:
        logger.exception("validate_fairness.main exception error=%s", exc)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
