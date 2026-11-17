"""Opt-in reranking quality and concurrency benchmark. See README.md."""

from __future__ import annotations

import argparse
from collections import Counter
from http.client import HTTPSConnection
from importlib.metadata import version
import json
import math
import os
import sys
from pathlib import Path
from statistics import mean
from time import perf_counter
from typing import Any


try:
    from .concurrency import RequestStats, run_requests
    from .load_test import load_sweep
    from .reporting import print_report, write_results
    from .results import EvaluationMetrics, RankedResult
except ImportError:
    from concurrency import RequestStats, run_requests
    from load_test import load_sweep
    from reporting import print_report, write_results
    from results import EvaluationMetrics, RankedResult

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


def metrics(
    ranked_ids: list[str], relevance: dict[str, int], k: int = 10
) -> EvaluationMetrics:
    """Evaluate an ordered run against candidate-pool qrels using ir_measures.

    Rank-derived scores preserve stable provider ordering, including ties.
    Gains are transformed only for nDCG to retain the historic 2**grade-1 definition.
    """
    import ir_measures as ir

    if k <= 0:
        raise ValueError("cutoff must be positive")
    if len(set(ranked_ids)) != len(ranked_ids):
        raise ValueError("run document IDs must be unique")
    # Rank-derived scores encode the already-established stable total order.
    run = {}
    for rank, doc_id in enumerate(ranked_ids):
        run.setdefault(doc_id, float(len(ranked_ids) - rank))
    if not relevance or not any(relevance.values()) or not run:
        return EvaluationMetrics(ndcg=0, reciprocal_rank=0, recall=0)
    qrels = {"query": relevance}
    runs = {"query": run}
    gains = {"query": {doc: 2**grade - 1 for doc, grade in relevance.items()}}
    ndcg = ir.nDCG @ k
    rr, recall = ir.RR @ k, ir.R @ k
    quality = ir.calc_aggregate([rr, recall], qrels, runs)
    return EvaluationMetrics(
        ndcg=ir.calc_aggregate([ndcg], gains, runs)[ndcg],
        reciprocal_rank=quality[rr],
        recall=quality[recall],
    )


def percentile(values: list[float], fraction: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    position = (len(ordered) - 1) * fraction
    lo, hi = math.floor(position), math.ceil(position)
    return ordered[lo] + (ordered[hi] - ordered[lo]) * (position - lo)


def map_results(response: Any, candidates: list[dict]) -> list[RankedResult]:
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
        RankedResult(
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


def sdk_cost(response: Any, key: str) -> float | None:
    # Private LiteLLM response surface; the version pin asserted in
    # make_reranker guards this access.
    return response._hidden_params.get(key)


def make_reranker(spec: dict, allow_download: bool):
    if version("litellm") != "1.106.4":
        raise RuntimeError("benchmark requires LiteLLM 1.106.4")
    if spec["provider"] == "local":
        from sentence_transformers import CrossEncoder
        from hawki_reranking.local_reranker import LocalReranker

        started = perf_counter()
        model = CrossEncoder(
            spec["model"], device="cpu", local_files_only=not allow_download
        )
        initialization = perf_counter() - started
        return LocalReranker(
            spec["model"], model_factory=lambda _name: model
        ), initialization

    import litellm
    from hawki_reranking.litellm_reranker import LiteLLMReranker

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
    concurrency: int = 1,
    progress: bool = False,
    requests_per_model: int | None = None,
    worker_requests: int | None = None,
    factory=make_reranker,
    clock=perf_counter,
) -> dict:
    if repetitions < 1 or max_remote_calls < 0 or concurrency < 1:
        raise ValueError(
            "repetitions/concurrency must be positive; remote limit must be non-negative"
        )
    if concurrency > 1 and any(spec["provider"] == "local" for spec in models):
        raise ValueError(
            "Parallel benchmarks support remote models only; select --models jina, cohere or voyage"
        )
    if requests_per_model is not None and requests_per_model < 1:
        raise ValueError("requests_per_model must be positive")
    if worker_requests is not None and (
        worker_requests < 1 or requests_per_model != concurrency * worker_requests
    ):
        raise ValueError(
            "worker_requests requires exactly concurrency * worker_requests calls"
        )
    candidates = data["candidates"]
    ids = [item["id"] for item in candidates]
    if not candidates or len(set(ids)) != len(ids) or not data["queries"]:
        raise ValueError("dataset needs unique candidate IDs and queries")
    query_counts = Counter(query["id"] for query in data["queries"])
    duplicate_ids = [query_id for query_id, count in query_counts.items() if count > 1]
    if duplicate_ids:
        duplicates = ", ".join(repr(query_id) for query_id in duplicate_ids)
        raise ValueError(
            f"Duplicate query IDs: {duplicates}. Give each query a unique ID."
        )
    for query in data["queries"]:
        if query.get("relevance") is None:
            continue
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
    total_requests = requests_per_model or len(data["queries"]) * repetitions
    report = {
        "planned_remote_calls": len(remote) * total_requests,
        "remote_calls_attempted": 0,
        "concurrency": concurrency,
        "calls": [],
        "errors": [],
        "skips": [],
        "summary": [],
    }
    if all(query.get("relevance") is not None for query in data["queries"]):
        report["qrels"] = {q["id"]: q["relevance"] for q in data["queries"]}
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
            client, initialization = factory(spec, allow_download)
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
        errors_before = len(report["errors"])
        stats = RequestStats()

        def jobs():
            for index in range(total_requests):
                repetition, query_index = divmod(index, len(data["queries"]))
                query = data["queries"][query_index]
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
                yield repetition, query

        def show_progress(current: RequestStats) -> None:
            print(
                f"model={spec['name']} concurrency={concurrency} "
                f"in_flight={current.active} peak={current.peak} "
                f"completed={current.completed} failed={current.failures}",
                file=sys.stderr,
                flush=True,
            )

        phase_started = perf_counter()
        for (repetition, query), outcome in run_requests(
            jobs(),
            lambda query: client.rerank(
                query["query"], list(documents), top_n=len(documents)
            ),
            concurrency=concurrency,
            stats=stats,
            clock=clock,
            progress=show_progress if progress else None,
            worker_requests=worker_requests,
        ):
            if outcome.error is not None:
                report["errors"].append(
                    {
                        "model": spec["name"],
                        "query": query["id"],
                        "repetition": repetition,
                        "stage": "rerank",
                        "latency_seconds": outcome.latency_seconds,
                        **outcome.error,
                    }
                )
                continue
            try:
                response = outcome.response
                elapsed = outcome.latency_seconds
                rankings = map_results(response, candidates)
                meta = response.meta or {}
                quality = (
                    metrics([r.id for r in rankings], query["relevance"]).model_dump(
                        by_alias=True
                    )
                    if query.get("relevance") is not None
                    else {key: None for key in ("ndcg@10", "mrr@10", "recall@10")}
                )
                row = {
                    "model": spec["name"],
                    "model_id": spec["model"],
                    "query": query["id"],
                    "repetition": repetition,
                    "latency_seconds": elapsed,
                    "rankings": [r.model_dump(mode="json") for r in rankings],
                    "usage": {
                        k: meta[k] for k in ("tokens", "billed_units") if k in meta
                    }
                    or None,
                    "actual_cost_usd": None,
                    "sdk_estimated_cost_usd": sdk_cost(response, "response_cost"),
                    "sdk_provider_cost_usd": sdk_cost(response, "cost"),
                    **quality,
                }
                calls.append(row)
                report["calls"].append(row)
            except Exception as exc:
                report["errors"].append(
                    {
                        "model": spec["name"],
                        "query": query["id"],
                        "repetition": repetition,
                        "stage": "evaluation",
                        "type": type(exc).__name__,
                        "latency_seconds": outcome.latency_seconds,
                    }
                )
        duration = perf_counter() - phase_started
        if progress:
            show_progress(stats)
        phase_errors = report["errors"][errors_before:]
        all_latencies = [row["latency_seconds"] for row in calls + phase_errors]
        report["summary"].append(
            {
                "model": spec["name"],
                "concurrency": concurrency,
                "peak_in_flight": stats.peak,
                "attempted_calls": stats.completed,
                "failed_calls": len(report["errors"]) - errors_before,
                "elapsed_seconds": duration,
                "attempted_calls_per_second": stats.completed / duration
                if duration
                else None,
                "all_p50_seconds": percentile(all_latencies, 0.5),
                "all_p95_seconds": percentile(all_latencies, 0.95),
                "max_seconds": max(all_latencies, default=None),
                "error_counts": dict(Counter(row["type"] for row in phase_errors)),
                "successful_calls_per_second": len(calls) / duration
                if duration
                else None,
                "successful_calls": len(calls),
                "initialization_seconds": initialization,
                "p50_seconds": percentile([c["latency_seconds"] for c in calls], 0.5),
                "p95_seconds": percentile([c["latency_seconds"] for c in calls], 0.95),
                **{
                    key: mean(c[key] for c in calls if c[key] is not None)
                    if any(c[key] is not None for c in calls)
                    else None
                    for key in ("ndcg@10", "mrr@10", "recall@10")
                },
            }
        )
    return report


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--models", nargs="+")
    parser.add_argument("--config", type=Path, default=ROOT / "models.json")
    parser.add_argument("--dataset", type=Path)
    parser.add_argument("--output", type=Path, default=ROOT / "results")
    parser.add_argument("--execute", action="store_true")
    parser.add_argument("--allow-remote", action="store_true")
    parser.add_argument("--allow-download", action="store_true")
    parser.add_argument("--env-file", type=Path)
    parser.add_argument("--repetitions", type=int, default=1)
    parser.add_argument("--max-remote-calls", type=int, default=9)
    parser.add_argument("--concurrency", type=int, default=1)
    parser.add_argument(
        "--load-test",
        action="store_true",
        help="Use the synthetic load payload and stepped worker bursts",
    )
    parser.add_argument(
        "--levels",
        default="24,32,48,64",
        help="Load-test concurrency levels, comma-separated",
    )
    parser.add_argument(
        "--per", type=int, default=1, help="Requests per worker at each load-test level"
    )
    parser.add_argument(
        "--cooldown", type=float, default=3, help="Seconds between load-test levels"
    )
    args = parser.parse_args()
    if args.env_file:
        load_gateway_environment(args.env_file)
    try:
        models = select_models(json.loads(args.config.read_text()), args.models)
        if args.load_test:
            if args.dataset or args.repetitions != 1 or args.concurrency != 1:
                raise ValueError(
                    "--load-test uses --levels and --per; omit --dataset, --repetitions and --concurrency"
                )
            report = load_sweep(
                models,
                levels=[int(value) for value in args.levels.split(",")],
                per=args.per,
                cooldown=args.cooldown,
                max_remote_calls=args.max_remote_calls,
                execute=args.execute,
                allow_remote=args.allow_remote,
                run_benchmark=benchmark,
                factory=make_reranker,
                on_phase=lambda current: write_results(current, args.output),
            )
        else:
            data = json.loads((args.dataset or ROOT / "dataset.json").read_text())
            report = benchmark(
                models,
                data,
                execute=args.execute,
                allow_remote=args.allow_remote,
                allow_download=args.allow_download,
                repetitions=args.repetitions,
                max_remote_calls=args.max_remote_calls,
                concurrency=args.concurrency,
                progress=args.concurrency > 1,
            )
    except ValueError as exc:
        parser.error(str(exc))
    write_results(report, args.output)
    print_report(report, args.output)


if __name__ == "__main__":
    main()
