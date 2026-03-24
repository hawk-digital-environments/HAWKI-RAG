from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
from typing import Any


def _load_jsonl(path: Path) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8").splitlines():
        stripped = line.strip()
        if not stripped:
            continue
        rows.append(json.loads(stripped))
    return rows


def _resolve_metrics() -> list[Any]:
    """Load metric objects from ragas.metrics.collections (ragas>=0.4)."""
    from ragas.metrics import collections as m  # type: ignore

    resolved: list[Any] = []
    # Common names across versions
    candidates = [
        "faithfulness",
        "answer_relevancy",
        "answer_relevance",
        "context_precision",
        "context_recall",
    ]
    seen = set()
    for name in candidates:
        metric = getattr(m, name, None)
        if metric is None:
            continue
        metric_name = getattr(metric, "name", name)
        if metric_name in seen:
            continue
        seen.add(metric_name)
        resolved.append(metric)
    return resolved


def main() -> int:
    parser = argparse.ArgumentParser(description="Run Ragas evaluation on a JSONL dataset.")
    parser.add_argument("--input", required=True, help="Input JSONL path")
    parser.add_argument("--output", required=True, help="Output directory")
    parser.add_argument("--openai-api-key", default="", help="OPENAI_API_KEY override")
    args = parser.parse_args()

    input_path = Path(args.input)
    output_dir = Path(args.output)
    output_dir.mkdir(parents=True, exist_ok=True)

    rows = _load_jsonl(input_path)
    if not rows:
        raise SystemExit("Input dataset is empty.")

    required = {"question", "answer", "contexts"}
    for idx, row in enumerate(rows):
        missing = sorted(required - set(row.keys()))
        if missing:
            raise SystemExit(f"Row {idx} missing required keys: {missing}")

    cli_key = str(args.openai_api_key or "").strip()
    if cli_key:
        os.environ["OPENAI_API_KEY"] = cli_key
    if not os.environ.get("OPENAI_API_KEY", "").strip():
        raise SystemExit(
            "Missing OPENAI_API_KEY. Set env var OPENAI_API_KEY or pass --openai-api-key."
        )

    from datasets import Dataset  # type: ignore
    from ragas import evaluate  # type: ignore

    dataset = Dataset.from_list(rows)
    metrics = _resolve_metrics()
    if not metrics:
        raise SystemExit("No compatible Ragas metrics found in installed version.")

    result = evaluate(dataset=dataset, metrics=metrics)

    # Try modern helpers first; fallback to dict casting for compatibility.
    summary: dict[str, Any]
    per_case: list[dict[str, Any]]
    if hasattr(result, "to_pandas"):
        df = result.to_pandas()
        per_case = df.to_dict(orient="records")
        summary = {
            key: float(df[key].mean())
            for key in df.columns
            if key not in ("question", "answer", "contexts", "ground_truth")
        }
        df.to_csv(output_dir / "summary.csv", index=False)
    else:
        payload = dict(result)
        per_case = [payload]
        summary = payload
        (output_dir / "summary.csv").write_text("metric,value\n" + "\n".join(f"{k},{v}" for k, v in summary.items()), encoding="utf-8")

    (output_dir / "summary.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False), encoding="utf-8")
    (output_dir / "per_case.json").write_text(json.dumps({"rows": per_case}, indent=2, ensure_ascii=False), encoding="utf-8")

    print(f"Ragas evaluation complete: {output_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
