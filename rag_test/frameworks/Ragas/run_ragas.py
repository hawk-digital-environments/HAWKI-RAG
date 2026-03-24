from __future__ import annotations

import argparse
import importlib.util
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


def _normalize_rows(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    normalized: list[dict[str, Any]] = []
    for idx, row in enumerate(rows):
        if "question" not in row:
            raise SystemExit(f"Row {idx} missing required key: question")
        if "answer" not in row:
            raise SystemExit(f"Row {idx} missing required key: answer")
        if "contexts" not in row:
            raise SystemExit(f"Row {idx} missing required key: contexts")
        normalized.append(
            {
                **row,
                "response": row["answer"],
                "reference": row.get("ground_truth", ""),
            }
        )
    return normalized


def _resolve_keyless_metrics() -> tuple[list[Any], list[str]]:
    """Resolve metrics that do not require OpenAI keys."""
    from ragas.metrics.collections import ExactMatch, StringPresence  # type: ignore

    resolved: list[Any] = [ExactMatch(), StringPresence()]
    warnings: list[str] = []

    try:
        from ragas.metrics.collections import RougeScore  # type: ignore

        if importlib.util.find_spec("rouge_score") is None:
            raise ImportError("missing package 'rouge_score'")
        resolved.append(RougeScore())
    except Exception as exc:
        warnings.append(f"Skipping RougeScore: {exc}")

    try:
        from ragas.metrics.collections import BleuScore  # type: ignore

        if importlib.util.find_spec("sacrebleu") is None:
            raise ImportError("missing package 'sacrebleu'")
        resolved.append(BleuScore())
    except Exception as exc:
        warnings.append(f"Skipping BleuScore: {exc}")

    try:
        from ragas.metrics.collections import NonLLMStringSimilarity  # type: ignore

        if importlib.util.find_spec("rapidfuzz") is None:
            raise ImportError("missing package 'rapidfuzz'")
        resolved.append(NonLLMStringSimilarity())
    except Exception as exc:
        warnings.append(f"Skipping NonLLMStringSimilarity: {exc}")

    return resolved, warnings


def _run_keyless_eval(rows: list[dict[str, Any]], metrics: list[Any]) -> tuple[dict[str, float], list[dict[str, Any]]]:
    per_case: list[dict[str, Any]] = []
    for row in rows:
        entry = {
            "question": row["question"],
            "answer": row["answer"],
            "ground_truth": row.get("ground_truth", ""),
        }
        per_case.append(entry)

    for metric in metrics:
        batch_inputs = [
            {"response": row["response"], "reference": row.get("reference", "")}
            for row in rows
        ]
        results = metric.batch_score(batch_inputs)
        for idx, metric_result in enumerate(results):
            per_case[idx][metric.name] = float(metric_result.value)

    summary: dict[str, float] = {}
    for metric in metrics:
        values = [float(case.get(metric.name, 0.0)) for case in per_case]
        summary[metric.name] = (sum(values) / len(values)) if values else 0.0

    return summary, per_case


def _write_outputs(output_dir: Path, summary: dict[str, Any], per_case: list[dict[str, Any]]) -> None:
    try:
        import pandas as pd  # type: ignore

        pd.DataFrame(per_case).to_csv(output_dir / "summary.csv", index=False)
    except Exception:
        headers = sorted({k for row in per_case for k in row.keys()})
        lines = [",".join(headers)]
        for row in per_case:
            lines.append(",".join(str(row.get(h, "")) for h in headers))
        (output_dir / "summary.csv").write_text("\n".join(lines) + "\n", encoding="utf-8")

    (output_dir / "summary.json").write_text(
        json.dumps(summary, indent=2, ensure_ascii=False), encoding="utf-8"
    )
    (output_dir / "per_case.json").write_text(
        json.dumps({"rows": per_case}, indent=2, ensure_ascii=False), encoding="utf-8"
    )


def _run_llm_eval(rows: list[dict[str, Any]]) -> tuple[dict[str, Any], list[dict[str, Any]]]:
    from datasets import Dataset  # type: ignore
    from ragas import evaluate  # type: ignore
    from ragas.metrics import (  # type: ignore
        answer_relevancy,
        context_precision,
        context_recall,
        faithfulness,
    )

    dataset = Dataset.from_list(rows)
    result = evaluate(
        dataset=dataset,
        metrics=[faithfulness, answer_relevancy, context_precision, context_recall],
    )
    if not hasattr(result, "to_pandas"):
        payload = dict(result)
        return payload, [payload]

    df = result.to_pandas()
    summary = {
        key: float(df[key].mean())
        for key in df.columns
        if key not in ("question", "answer", "response", "contexts", "ground_truth", "reference")
    }
    return summary, df.to_dict(orient="records")


def _resolve_mode(requested_mode: str) -> str:
    mode = requested_mode.strip().lower()
    if mode not in {"auto", "keyless", "llm"}:
        raise SystemExit("--mode must be one of: auto, keyless, llm")
    if mode != "auto":
        return mode
    return "llm" if os.environ.get("OPENAI_API_KEY", "").strip() else "keyless"


def main() -> int:
    parser = argparse.ArgumentParser(description="Run Ragas evaluation on a JSONL dataset.")
    parser.add_argument("--input", required=True, help="Input JSONL path")
    parser.add_argument("--output", required=True, help="Output directory")
    parser.add_argument("--mode", default="auto", help="auto|keyless|llm")
    parser.add_argument("--openai-api-key", default="", help="OPENAI_API_KEY override")
    args = parser.parse_args()

    input_path = Path(args.input)
    output_dir = Path(args.output)
    output_dir.mkdir(parents=True, exist_ok=True)

    rows = _load_jsonl(input_path)
    if not rows:
        raise SystemExit("Input dataset is empty.")
    rows = _normalize_rows(rows)

    cli_key = str(args.openai_api_key or "").strip()
    if cli_key:
        os.environ["OPENAI_API_KEY"] = cli_key

    mode = _resolve_mode(args.mode)
    if mode == "llm" and not os.environ.get("OPENAI_API_KEY", "").strip():
        raise SystemExit("LLM mode requires OPENAI_API_KEY (or pass --openai-api-key).")

    if mode == "keyless":
        metrics, warnings = _resolve_keyless_metrics()
        if not metrics:
            raise SystemExit("No keyless metrics available.")
        for warning in warnings:
            print(f"[warn] {warning}")
        summary, per_case = _run_keyless_eval(rows, metrics)
        _write_outputs(output_dir, summary, per_case)
        print(f"Ragas keyless evaluation complete: {output_dir}")
        return 0

    summary, per_case = _run_llm_eval(rows)
    _write_outputs(output_dir, summary, per_case)
    print(f"Ragas llm evaluation complete: {output_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
