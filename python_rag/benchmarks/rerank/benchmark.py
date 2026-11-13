"""Sequential, opt-in reranking benchmark. See README.md for execution."""

from __future__ import annotations

import argparse
import csv
from http.client import HTTPSConnection
from importlib.metadata import version
import json
import math
import os
from pathlib import Path
from statistics import mean
from time import perf_counter
from typing import Any


ROOT = Path(__file__).resolve().parent
BASES = {
    "cohere": "https://api.cohere.com",
    "voyage": "https://api.voyageai.com/v1",
}


def load_gateway_environment(path: Path) -> None:
    """Read only the two gateway variables; never log or export their values."""
    from dotenv import dotenv_values

    values = dotenv_values(path, interpolate=False)
    for key in ("LITELLM_PROXY_API_BASE", "LITELLM_PROXY_API_KEY"):
        if values.get(key):
            os.environ[key] = values[key]


def select_models(config: list[dict], names: list[str] | None) -> list[dict]:
    available = {item["name"] for item in config}
    if len(available) != len(config) or (names and set(names) - available):
        raise ValueError("duplicate model names or unknown model selection")
    return [item for item in config if names is None or item["name"] in names]


def skip_reason(spec: dict, execute: bool, allow_remote: bool, env: dict) -> str | None:
    if spec.get("skip") or not spec.get("model"):
        return spec.get("skip", "unverified model")
    if not execute:
        return "dry-run"
    if spec["provider"] != "local":
        if not allow_remote:
            return "remote calls require --allow-remote"
        if not env.get(spec["key_env"], "").strip():
            return "missing API key"
        if (
            spec["provider"] == "litellm_proxy"
            and not env.get("LITELLM_PROXY_API_BASE", "").strip()
        ):
            return "missing LITELLM_PROXY_API_BASE"
    return None


def metrics(ranked_ids: list[str], relevance: dict[str, int], k: int = 10) -> dict:
    grades = [relevance.get(doc_id, 0) for doc_id in ranked_ids[:k]]
    dcg = sum((2**grade - 1) / math.log2(rank + 2) for rank, grade in enumerate(grades))
    ideal = sorted(relevance.values(), reverse=True)[:k]
    idcg = sum((2**grade - 1) / math.log2(rank + 2) for rank, grade in enumerate(ideal))
    total = sum(grade > 0 for grade in relevance.values())
    return {
        "ndcg@10": dcg / idcg if idcg else 0.0,
        "mrr@10": next(
            (1 / rank for rank, grade in enumerate(grades, 1) if grade > 0), 0.0
        ),
        "recall@10": sum(grade > 0 for grade in grades) / total if total else 0.0,
    }


def percentile(values: list[float], fraction: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    position = (len(ordered) - 1) * fraction
    lo, hi = math.floor(position), math.ceil(position)
    return ordered[lo] + (ordered[hi] - ordered[lo]) * (position - lo)


def map_results(response: Any, candidates: list[dict]) -> list[dict]:
    from litellm.types.rerank import RerankResponse

    if not isinstance(response, RerankResponse) or response.results is None:
        raise ValueError("expected native RerankResponse")
    results = response.results
    indices = [item["index"] for item in results]
    if any(type(index) is not int for index in indices) or sorted(indices) != list(
        range(len(candidates))
    ):
        raise ValueError("expected every original index exactly once")
    scores = [item["relevance_score"] for item in results]
    if not all(
        isinstance(score, (int, float))
        and not isinstance(score, bool)
        and math.isfinite(score)
        for score in scores
    ):
        raise ValueError("invalid relevance scores")
    # Evaluate every provider's results in descending score order.
    results = sorted(results, key=lambda item: item["relevance_score"], reverse=True)
    return [
        dict(
            index=item["index"],
            id=candidates[item["index"]]["id"],
            score=item["relevance_score"],
        )
        for item in results
    ]


def gateway_base() -> str:
    base = os.environ.get("LITELLM_PROXY_API_BASE", "").strip().rstrip("/")
    if base not in ("https://ai.ixdlab.hawk.de", "https://ai.ixdlab.hawk.de/v1"):
        raise ValueError("LITELLM_PROXY_API_BASE must point to the IxDLab gateway")
    return "https://ai.ixdlab.hawk.de"


def verify_gateway_model(spec: dict) -> None:
    """Discover aliases only; all reranking still goes through the LiteLLM SDK."""
    gateway_base()
    if not spec["model"].startswith("litellm_proxy/"):
        raise ValueError("gateway models need the litellm_proxy/ SDK prefix")
    connection = HTTPSConnection("ai.ixdlab.hawk.de", timeout=30)
    try:
        connection.request(
            "GET",
            "/v1/models",
            headers={"Authorization": "Bearer " + os.environ["LITELLM_PROXY_API_KEY"]},
        )
        response = connection.getresponse()
        if response.status in (401, 403):
            raise PermissionError("gateway model discovery authentication failed")
        if response.status != 200:
            raise ConnectionError("gateway model discovery endpoint failed")
        aliases = {item["id"] for item in json.loads(response.read())["data"]}
        if spec["model"].removeprefix("litellm_proxy/") not in aliases:
            raise LookupError("configured model alias is absent from gateway response")
    finally:
        connection.close()


def make_reranker(spec: dict, allow_download: bool):
    if version("litellm") != "1.106.4":
        raise RuntimeError("benchmark requires LiteLLM 1.106.4")
    if spec["provider"] == "local":
        from sentence_transformers import CrossEncoder
        from hawki_bridge.adapters.local_reranker import LocalReranker

        started = perf_counter()
        model = CrossEncoder(
            spec["model"], device="cpu", local_files_only=not allow_download
        )
        initialization = perf_counter() - started
        return LocalReranker(
            spec["model"], model_factory=lambda _name: model
        ), initialization

    import litellm
    from hawki_bridge.adapters.litellm_reranker import LiteLLMReranker

    provider = spec["provider"]
    if provider == "litellm_proxy":
        verify_gateway_model(spec)
        base = gateway_base()
        key_env = "LITELLM_PROXY_API_KEY"
    elif provider in BASES and spec["api_base"] == BASES[provider]:
        base = BASES[provider]
        key_env = spec["key_env"]
    else:
        raise ValueError("only official hosted provider endpoints are allowed")

    def sdk_call(**kwargs):
        return litellm.rerank(
            **kwargs,
            custom_llm_provider=provider,
            api_base=base,
            api_key=os.environ[key_env],
            num_retries=0,
            max_retries=0,
            timeout=60,
            caching=False,
        )

    return LiteLLMReranker(spec["model"], rerank_fn=sdk_call), None


def benchmark(
    models: list[dict],
    data: dict,
    *,
    execute: bool = False,
    allow_remote: bool = False,
    allow_download: bool = False,
    repetitions: int = 1,
    max_remote_calls: int = 9,
    factory=make_reranker,
    clock=perf_counter,
) -> dict:
    if repetitions < 1 or max_remote_calls < 0:
        raise ValueError(
            "repetitions must be positive; remote limit must be non-negative"
        )
    candidates = data["candidates"]
    ids = [item["id"] for item in candidates]
    if not candidates or len(set(ids)) != len(ids) or not data["queries"]:
        raise ValueError("dataset needs unique candidate IDs and queries")
    for query in data["queries"]:
        if set(query["relevance"]) - set(ids) or any(
            type(g) is not int or g < 0 for g in query["relevance"].values()
        ):
            raise ValueError("invalid relevance labels")
    # No model or SDK imports on a default dry-run.
    os.environ.setdefault("LITELLM_LOCAL_MODEL_COST_MAP", "True")
    from hawki_bridge.adapters.reranker_documents import extract_rerank_documents

    documents = extract_rerank_documents(candidates)
    remote = [
        m
        for m in models
        if m["provider"] != "local" and m.get("model") and not m.get("skip")
    ]
    report = {
        "planned_remote_calls": len(remote) * len(data["queries"]) * repetitions,
        "remote_calls_attempted": 0,
        "calls": [],
        "errors": [],
        "skips": [],
        "summary": [],
    }
    for spec in models:
        reason = skip_reason(spec, execute, allow_remote, dict(os.environ))
        if reason:
            report["skips"].append({"model": spec["name"], "reason": reason})
            continue
        if (
            spec["provider"] != "local"
            and report["remote_calls_attempted"] >= max_remote_calls
        ):
            report["skips"].append(
                {"model": spec["name"], "reason": "remote call limit"}
            )
            continue
        try:
            adapter, initialization = factory(spec, allow_download)
        except Exception as exc:
            # Exception strings may contain credentials or request headers.
            report["errors"].append(
                {
                    "model": spec["name"],
                    "stage": "initialization",
                    "type": type(exc).__name__,
                }
            )
            continue
        calls = []
        for repetition in range(repetitions):
            for query in data["queries"]:
                if spec["provider"] != "local":
                    if report["remote_calls_attempted"] >= max_remote_calls:
                        report["skips"].append(
                            {
                                "model": spec["name"],
                                "query": query["id"],
                                "reason": "remote call limit",
                            }
                        )
                        continue
                    report["remote_calls_attempted"] += 1
                started = clock()
                try:
                    response = adapter.rerank(
                        query["query"], list(documents), top_n=len(documents)
                    )
                    elapsed = clock() - started
                    rankings = map_results(response, candidates)
                    meta = response.meta or {}
                    row = {
                        "model": spec["name"],
                        "model_id": spec["model"],
                        "query": query["id"],
                        "repetition": repetition,
                        "latency_seconds": elapsed,
                        "rankings": rankings,
                        "usage": {
                            k: meta[k] for k in ("tokens", "billed_units") if k in meta
                        }
                        or None,
                        "actual_cost_usd": None,
                        "sdk_estimated_cost_usd": response._hidden_params.get(
                            "response_cost"
                        ),
                        "sdk_provider_cost_usd": response._hidden_params.get("cost"),
                        **metrics([r["id"] for r in rankings], query["relevance"]),
                    }
                    calls.append(row)
                    report["calls"].append(row)
                except Exception as exc:
                    report["errors"].append(
                        {
                            "model": spec["name"],
                            "query": query["id"],
                            "stage": "rerank",
                            "type": type(exc).__name__,
                            "latency_seconds": clock() - started,
                        }
                    )
        report["summary"].append(
            {
                "model": spec["name"],
                "successful_calls": len(calls),
                "initialization_seconds": initialization,
                "p50_seconds": percentile([c["latency_seconds"] for c in calls], 0.5),
                "p95_seconds": percentile([c["latency_seconds"] for c in calls], 0.95),
                **{
                    key: mean(c[key] for c in calls) if calls else None
                    for key in ("ndcg@10", "mrr@10", "recall@10")
                },
            }
        )
    return report


def write_results(report: dict, output: Path) -> None:
    output.mkdir(parents=True, exist_ok=True)
    (output / "results.json").write_text(
        json.dumps(report, indent=2, allow_nan=False) + "\n", encoding="utf-8"
    )
    for section in ("summary", "calls", "errors", "skips"):
        rows = report[section]
        fields = list(dict.fromkeys(key for row in rows for key in row)) or ["model"]
        with (output / f"{section}.csv").open(
            "w", newline="", encoding="utf-8"
        ) as stream:
            writer = csv.DictWriter(stream, fieldnames=fields)
            writer.writeheader()
            for row in rows:
                writer.writerow(
                    {
                        k: json.dumps(v) if isinstance(v, (dict, list)) else v
                        for k, v in row.items()
                    }
                )


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--models", nargs="+")
    parser.add_argument("--config", type=Path, default=ROOT / "models.json")
    parser.add_argument("--dataset", type=Path, default=ROOT / "dataset.json")
    parser.add_argument("--output", type=Path, default=ROOT / "results")
    parser.add_argument("--execute", action="store_true")
    parser.add_argument("--allow-remote", action="store_true")
    parser.add_argument("--allow-download", action="store_true")
    parser.add_argument("--env-file", type=Path)
    parser.add_argument("--repetitions", type=int, default=1)
    parser.add_argument("--max-remote-calls", type=int, default=9)
    args = parser.parse_args()
    if args.env_file:
        load_gateway_environment(args.env_file)
    try:
        models = select_models(json.loads(args.config.read_text()), args.models)
        data = json.loads(args.dataset.read_text())
        report = benchmark(
            models,
            data,
            execute=args.execute,
            allow_remote=args.allow_remote,
            allow_download=args.allow_download,
            repetitions=args.repetitions,
            max_remote_calls=args.max_remote_calls,
        )
    except ValueError as exc:
        parser.error(str(exc))
    write_results(report, args.output)
    print(
        f"Planned remote calls (before key/permission checks): {report['planned_remote_calls']}; "
        f"attempted: {report['remote_calls_attempted']}"
    )
    print("Model             Calls   P50(s)   P95(s)   nDCG@10   MRR@10   Recall@10")
    for row in report["summary"]:
        values = [
            "unknown" if row[k] is None else f"{row[k]:.4f}"
            for k in ("p50_seconds", "p95_seconds", "ndcg@10", "mrr@10", "recall@10")
        ]
        print(f"{row['model']:<17} {row['successful_calls']:>5} " + " ".join(values))
    print(
        f"Errors: {len(report['errors'])}; skips: {len(report['skips'])}; output: {args.output}"
    )


if __name__ == "__main__":
    main()
