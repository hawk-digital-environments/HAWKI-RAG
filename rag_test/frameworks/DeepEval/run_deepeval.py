from __future__ import annotations

import argparse
import json
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any


def _load_jsonl(path: Path) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8").splitlines():
        s = line.strip()
        if not s:
            continue
        rows.append(json.loads(s))
    return rows


def _post_json(url: str, payload: dict[str, Any], timeout: float = 60.0) -> dict[str, Any]:
    req = urllib.request.Request(
        url=url,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {exc.code} calling {url}: {body}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Failed calling {url}: {exc}") from exc


def _model_from_args(provider: str, model_name: str, ollama_base_url: str):
    p = provider.strip().lower()
    if p == "ollama":
        from deepeval.models import OllamaModel  # type: ignore

        base_url = (ollama_base_url or "").strip()
        if base_url:
            return OllamaModel(model_name, base_url=base_url)
        return OllamaModel(model_name)
    # fallback to model string for default provider integrations
    return model_name


def _normalize_eval_rows(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    for i, row in enumerate(rows):
        if "question" not in row:
            raise SystemExit(f"Row {i} missing required key: question")
        out.append(
            {
                "question": str(row["question"]),
                "answer": str(row.get("answer") or ""),
                "contexts": list(row.get("contexts") or []),
                "ground_truth": str(row.get("ground_truth") or ""),
            }
        )
    return out


def _collect_live_rows(
    input_rows: list[dict[str, Any]],
    rag_base_url: str,
    provider: str,
    top_k: int,
) -> list[dict[str, Any]]:
    if not rag_base_url.strip():
        raise SystemExit("--source live requires --rag-base-url")
    base = rag_base_url.rstrip("/")
    query_url = f"{base}/query"
    out: list[dict[str, Any]] = []
    for i, row in enumerate(input_rows, start=1):
        q = str(row["question"])
        payload = {
            "query": q,
            "top_k": top_k,
            "provider": provider,
            "generate": False,
            "fast_mode": False,
            "smart_lookup": True,
        }
        response = _post_json(query_url, payload, timeout=120.0)
        hits = response.get("hits") or []
        contexts: list[str] = []
        for hit in hits[:top_k]:
            content = str((hit.get("payload") or {}).get("content") or "").strip()
            if content:
                contexts.append(content)
        answer = str(response.get("answer") or "").strip()
        if not answer and contexts:
            answer = contexts[0][:600]
        out.append(
            {
                "question": q,
                "answer": answer,
                "contexts": contexts,
                "ground_truth": str(row.get("ground_truth") or ""),
            }
        )
        print(f"[live] {i}/{len(input_rows)} collected")
    return out


def _write_rows(rows: list[dict[str, Any]], output_dir: Path) -> None:
    output_dir.mkdir(parents=True, exist_ok=True)
    (output_dir / "prepared_eval.jsonl").write_text(
        "\n".join(json.dumps(r, ensure_ascii=False) for r in rows) + "\n", encoding="utf-8"
    )


def _metric_objects_e2e(model, threshold: float):
    from deepeval.metrics import (  # type: ignore
        AnswerRelevancyMetric,
        ContextualPrecisionMetric,
        ContextualRecallMetric,
        ContextualRelevancyMetric,
        FaithfulnessMetric,
    )

    # Some DeepEval versions require model=..., others can infer default.
    def _make(metric_cls):
        try:
            return metric_cls(threshold=threshold, model=model)
        except TypeError:
            return metric_cls(threshold=threshold)

    return [
        _make(AnswerRelevancyMetric),
        _make(FaithfulnessMetric),
        _make(ContextualRelevancyMetric),
        _make(ContextualPrecisionMetric),
        _make(ContextualRecallMetric),
    ]


def _run_e2e(
    rows: list[dict[str, Any]],
    output_dir: Path,
    model,
    threshold: float,
) -> None:
    from deepeval import evaluate  # type: ignore
    from deepeval.test_case import LLMTestCase  # type: ignore

    metrics = _metric_objects_e2e(model, threshold)
    test_cases = [
        LLMTestCase(
            input=row["question"],
            actual_output=row["answer"],
            retrieval_context=row["contexts"],
            expected_output=row["ground_truth"] or None,
        )
        for row in rows
    ]
    evaluate(test_cases, metrics=metrics)
    (output_dir / "summary.json").write_text(
        json.dumps(
            {
                "mode": "e2e",
                "cases": len(test_cases),
                "metrics": [m.__class__.__name__ for m in metrics],
            },
            indent=2,
            ensure_ascii=False,
        ),
        encoding="utf-8",
    )


def _run_retriever_component(rows: list[dict[str, Any]], output_dir: Path, model, threshold: float) -> None:
    from deepeval.dataset import EvaluationDataset, Golden  # type: ignore
    from deepeval.metrics import ContextualRelevancyMetric  # type: ignore
    from deepeval.test_case import LLMTestCase  # type: ignore
    from deepeval.tracing import observe, update_current_span  # type: ignore

    try:
        metric = ContextualRelevancyMetric(threshold=threshold, model=model)
    except TypeError:
        metric = ContextualRelevancyMetric(threshold=threshold)

    by_input = {r["question"]: r for r in rows}

    @observe(metrics=[metric])
    def retriever(query: str):
        row = by_input.get(query, {"contexts": []})
        update_current_span(test_case=LLMTestCase(input=query, retrieval_context=row.get("contexts") or []))
        return row.get("contexts") or []

    dataset = EvaluationDataset(goldens=[Golden(input=r["question"]) for r in rows])
    for golden in dataset.evals_iterator():
        retriever(golden.input)

    (output_dir / "summary.json").write_text(
        json.dumps(
            {"mode": "retriever", "cases": len(rows), "metric": metric.__class__.__name__},
            indent=2,
            ensure_ascii=False,
        ),
        encoding="utf-8",
    )


def _run_generator_component(rows: list[dict[str, Any]], output_dir: Path, model, threshold: float) -> None:
    from deepeval.dataset import EvaluationDataset, Golden  # type: ignore
    from deepeval.metrics import AnswerRelevancyMetric, FaithfulnessMetric  # type: ignore
    from deepeval.test_case import LLMTestCase  # type: ignore
    from deepeval.tracing import observe, update_current_span  # type: ignore

    try:
        answer_rel = AnswerRelevancyMetric(threshold=threshold, model=model)
    except TypeError:
        answer_rel = AnswerRelevancyMetric(threshold=threshold)
    try:
        faith = FaithfulnessMetric(threshold=threshold, model=model)
    except TypeError:
        faith = FaithfulnessMetric(threshold=threshold)

    by_input = {r["question"]: r for r in rows}

    @observe(metrics=[answer_rel, faith])
    def generator(query: str):
        row = by_input.get(query, {"answer": "", "contexts": []})
        update_current_span(
            test_case=LLMTestCase(
                input=query,
                actual_output=row.get("answer") or "",
                retrieval_context=row.get("contexts") or [],
            )
        )
        return row.get("answer") or ""

    dataset = EvaluationDataset(goldens=[Golden(input=r["question"]) for r in rows])
    for golden in dataset.evals_iterator():
        generator(golden.input)

    (output_dir / "summary.json").write_text(
        json.dumps(
            {
                "mode": "generator",
                "cases": len(rows),
                "metrics": [answer_rel.__class__.__name__, faith.__class__.__name__],
            },
            indent=2,
            ensure_ascii=False,
        ),
        encoding="utf-8",
    )


def _run_multi_turn(conversation_rows: list[dict[str, Any]], output_dir: Path, model, threshold: float) -> None:
    from deepeval import evaluate  # type: ignore
    from deepeval.metrics import TurnFaithfulness, TurnRelevancy  # type: ignore
    from deepeval.test_case import ConversationalTestCase, Turn  # type: ignore

    test_cases = []
    for i, row in enumerate(conversation_rows):
        turns = row.get("turns")
        if not isinstance(turns, list):
            raise SystemExit(f"Conversation row {i} missing 'turns' array")
        case_turns = []
        for t in turns:
            case_turns.append(
                Turn(
                    role=t.get("role", ""),
                    content=t.get("content", ""),
                    retrieval_context=t.get("retrieval_context", None),
                )
            )
        test_cases.append(ConversationalTestCase(turns=case_turns))

    try:
        turn_faith = TurnFaithfulness(threshold=threshold, model=model)
    except TypeError:
        turn_faith = TurnFaithfulness(threshold=threshold)
    try:
        turn_rel = TurnRelevancy(threshold=threshold, model=model)
    except TypeError:
        turn_rel = TurnRelevancy(threshold=threshold)

    evaluate(test_cases, metrics=[turn_faith, turn_rel])

    (output_dir / "summary.json").write_text(
        json.dumps(
            {
                "mode": "multi_turn",
                "cases": len(test_cases),
                "metrics": [turn_faith.__class__.__name__, turn_rel.__class__.__name__],
            },
            indent=2,
            ensure_ascii=False,
        ),
        encoding="utf-8",
    )


def main() -> int:
    parser = argparse.ArgumentParser(description="DeepEval RAG quickstart runner (e2e/retriever/generator/multi_turn).")
    parser.add_argument("--mode", default="e2e", help="e2e|retriever|generator|multi_turn")
    parser.add_argument("--source", default="jsonl", help="jsonl|live (ignored for multi_turn)")
    parser.add_argument("--input", required=True, help="Input JSONL path")
    parser.add_argument("--output", required=True, help="Output directory")
    parser.add_argument("--rag-base-url", default="", help="RAG base URL used when --source live")
    parser.add_argument("--provider", default="ollama", help="Provider sent to live /query")
    parser.add_argument("--top-k", type=int, default=5, help="Top-k sent to live /query")
    parser.add_argument("--model-provider", default="ollama", help="deepeval model provider (ollama|default)")
    parser.add_argument("--model-name", default="deepseek-r1", help="deepeval metric model name")
    parser.add_argument(
        "--ollama-base-url",
        default="",
        help="Ollama base URL for model-provider=ollama (e.g. http://hawki_ollama:11434)",
    )
    parser.add_argument("--threshold", type=float, default=0.5, help="Metric threshold")
    args = parser.parse_args()

    mode = args.mode.strip().lower()
    if mode not in {"e2e", "retriever", "generator", "multi_turn"}:
        raise SystemExit("--mode must be one of: e2e, retriever, generator, multi_turn")

    input_path = Path(args.input)
    output_dir = Path(args.output)
    output_dir.mkdir(parents=True, exist_ok=True)
    rows = _load_jsonl(input_path)
    model = _model_from_args(args.model_provider, args.model_name, args.ollama_base_url)

    if mode == "multi_turn":
        _run_multi_turn(rows, output_dir, model, args.threshold)
        print(f"DeepEval {mode} complete: {output_dir}")
        return 0

    eval_rows = _normalize_eval_rows(rows)
    if args.source.strip().lower() == "live":
        eval_rows = _collect_live_rows(
            input_rows=eval_rows,
            rag_base_url=args.rag_base_url,
            provider=args.provider,
            top_k=max(1, args.top_k),
        )
    _write_rows(eval_rows, output_dir)

    if mode == "e2e":
        _run_e2e(eval_rows, output_dir, model, args.threshold)
    elif mode == "retriever":
        _run_retriever_component(eval_rows, output_dir, model, args.threshold)
    else:
        _run_generator_component(eval_rows, output_dir, model, args.threshold)

    print(f"DeepEval {mode} complete: {output_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
