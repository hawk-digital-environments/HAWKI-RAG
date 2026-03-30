from __future__ import annotations

import argparse
import csv
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


def _normalize(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    for i, row in enumerate(rows):
        for required in ("question", "answer", "contexts"):
            if required not in row:
                raise SystemExit(f"Row {i} missing required key: {required}")
        contexts = row.get("contexts", [])
        if not isinstance(contexts, list):
            raise SystemExit(f"Row {i} key 'contexts' must be a list of strings")

        out.append(
            {
                "question": row["question"],
                "answer": row["answer"],
                "ground_truth": row.get("ground_truth", ""),
                "contexts": contexts,
                # Vertex-friendly aliases:
                "prompt": row["question"],
                "response": row["answer"],
                "reference": row.get("ground_truth", ""),
                "context": "\n".join(str(c) for c in contexts),
            }
        )
    return out


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


def _questions_only(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    for i, row in enumerate(rows):
        if "question" not in row:
            raise SystemExit(f"Row {i} missing required key: question")
        out.append({"question": str(row["question"]), "ground_truth": row.get("ground_truth", "")})
    return out


def _to_eval_row_from_live(
    question: str,
    ground_truth: str,
    response: dict[str, Any],
    context_limit: int,
) -> dict[str, Any]:
    hits = response.get("hits") or []
    contexts: list[str] = []
    for hit in hits[:context_limit]:
        payload = hit.get("payload") or {}
        content = str(payload.get("content") or "").strip()
        if content:
            contexts.append(content)
    answer = str(response.get("answer") or "").strip()
    if not answer and contexts:
        answer = contexts[0][:600]
    kg = response.get("kg") or []
    return {
        "question": question,
        "answer": answer,
        "ground_truth": ground_truth,
        "contexts": contexts,
        "prompt": question,
        "response": answer,
        "reference": ground_truth,
        "context": "\n".join(contexts),
        "live_meta": {
            "hit_count": int(response.get("count") or 0),
            "kg_count": len(kg),
        },
    }


def _collect_live_rows(
    questions: list[dict[str, Any]],
    rag_base_url: str,
    provider: str,
    top_k: int,
    context_limit: int,
    fast_mode: bool,
    smart_lookup: bool,
    reranker: str,
) -> list[dict[str, Any]]:
    base = rag_base_url.rstrip("/")
    query_url = f"{base}/query"
    rows: list[dict[str, Any]] = []
    for i, row in enumerate(questions, start=1):
        payload = {
            "query": row["question"],
            "top_k": top_k,
            "provider": provider,
            "generate": False,
            "fast_mode": fast_mode,
            "smart_lookup": smart_lookup,
            "reranker": reranker,
        }
        response = _post_json(query_url, payload, timeout=120.0)
        rows.append(
            _to_eval_row_from_live(
                question=row["question"],
                ground_truth=str(row.get("ground_truth") or ""),
                response=response,
                context_limit=context_limit,
            )
        )
        print(f"[live] {i}/{len(questions)} collected")
    return rows


def _write_prepared(rows: list[dict[str, Any]], output_dir: Path) -> None:
    output_dir.mkdir(parents=True, exist_ok=True)
    prepared_jsonl = output_dir / "prepared.jsonl"
    prepared_csv = output_dir / "prepared.csv"

    prepared_jsonl.write_text(
        "\n".join(json.dumps(r, ensure_ascii=False) for r in rows) + "\n",
        encoding="utf-8",
    )
    headers = sorted({k for row in rows for k in row.keys()})
    with prepared_csv.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=headers)
        writer.writeheader()
        for row in rows:
            writer.writerow(row)


def _try_cloud_eval(
    rows: list[dict[str, Any]],
    output_dir: Path,
    project: str,
    location: str,
    metric_names: list[str],
) -> dict[str, Any]:
    try:
        import pandas as pd  # type: ignore
        import vertexai  # type: ignore
        from vertexai.evaluation import EvalTask  # type: ignore
    except Exception as exc:
        return {
            "cloud_run": "skipped",
            "reason": f"Vertex AI SDK import failed: {exc}",
        }

    if not project.strip():
        return {"cloud_run": "skipped", "reason": "Missing --project for cloud evaluation"}

    vertexai.init(project=project, location=location)
    df = pd.DataFrame(
        [{"prompt": r["prompt"], "response": r["response"], "reference": r["reference"]} for r in rows]
    )

    # API shape can vary by SDK version. Keep this guarded and fail-soft.
    try:
        task = EvalTask(dataset=df, metrics=metric_names)
        result = task.evaluate()
        payload: dict[str, Any] = {"cloud_run": "ok", "metrics": metric_names}

        if hasattr(result, "to_pandas"):
            res_df = result.to_pandas()
            res_df.to_csv(output_dir / "cloud_results.csv", index=False)
            payload["rows"] = len(res_df)
        else:
            payload["result"] = str(result)

        return payload
    except Exception as exc:
        return {
            "cloud_run": "failed",
            "reason": str(exc),
            "note": "Prepared files are still available for manual Vertex AI evaluation.",
        }


def main() -> int:
    parser = argparse.ArgumentParser(description="Prepare and optionally run Vertex AI gen AI evaluation.")
    parser.add_argument("--source", default="jsonl", help="jsonl|live")
    parser.add_argument("--input", required=True, help="Input JSONL path")
    parser.add_argument("--output", required=True, help="Output directory")
    parser.add_argument("--rag-base-url", default="", help="Live RAG base URL for --source live (required in live mode)")
    parser.add_argument("--provider", default="ollama", help="Provider used by /query in live mode")
    parser.add_argument("--top-k", type=int, default=5, help="Top-k for live retrieval calls")
    parser.add_argument("--context-limit", type=int, default=5, help="Number of hit contexts to store")
    parser.add_argument("--fast-mode", action="store_true", help="Enable fast_mode in live /query calls")
    parser.add_argument("--smart-lookup", action="store_true", help="Enable smart_lookup in live /query calls")
    parser.add_argument("--reranker", default="none", help="Reranker mode for live /query calls")
    parser.add_argument("--project", default="", help="GCP project id")
    parser.add_argument("--location", default="us-central1", help="Vertex AI location")
    parser.add_argument(
        "--metrics",
        default="exact_match,rouge_l,bleu",
        help="Comma-separated metric names for cloud eval",
    )
    parser.add_argument("--run-cloud", action="store_true", help="Run Vertex AI cloud evaluation")
    args = parser.parse_args()

    input_path = Path(args.input)
    output_dir = Path(args.output)
    source = args.source.strip().lower()
    if source not in {"jsonl", "live"}:
        raise SystemExit("--source must be jsonl or live")

    if source == "jsonl":
        rows = _normalize(_load_jsonl(input_path))
    else:
        if not args.rag_base_url.strip():
            raise SystemExit(
                "--source live requires --rag-base-url.\n"
                "Example (your setup): --rag-base-url http://hawki_rag_bridge:8000\n"
                "If running from host, use docker exec in hawki_rag_app."
            )
        questions = _questions_only(_load_jsonl(input_path))
        rows = _collect_live_rows(
            questions=questions,
            rag_base_url=args.rag_base_url,
            provider=args.provider,
            top_k=args.top_k,
            context_limit=max(1, args.context_limit),
            fast_mode=bool(args.fast_mode),
            smart_lookup=bool(args.smart_lookup),
            reranker=args.reranker,
        )

    _write_prepared(rows, output_dir)

    summary: dict[str, Any] = {
        "source": source,
        "rows": len(rows),
        "prepared_csv": str((output_dir / "prepared.csv").resolve()),
        "prepared_jsonl": str((output_dir / "prepared.jsonl").resolve()),
    }

    if args.run_cloud:
        metric_names = [m.strip() for m in args.metrics.split(",") if m.strip()]
        summary["cloud"] = _try_cloud_eval(
            rows=rows,
            output_dir=output_dir,
            project=args.project,
            location=args.location,
            metric_names=metric_names,
        )
    else:
        summary["cloud"] = {"cloud_run": "skipped", "reason": "--run-cloud not provided"}

    (output_dir / "summary.json").write_text(
        json.dumps(summary, indent=2, ensure_ascii=False), encoding="utf-8"
    )
    print(f"VertexAI evaluation scaffold complete: {output_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
