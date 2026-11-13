"""Evaluate fixed private candidate pools; only explicitly approved IxDLab traffic."""

import argparse
import hashlib
import json
from pathlib import Path
from statistics import mean
from time import perf_counter

try:
    from .benchmark import (
        ROOT,
        make_reranker,
        map_results,
        metrics,
        percentile,
        select_models,
        skip_reason,
        write_results,
        load_gateway_environment,
    )
    from .collect import FORMAT
except ImportError:
    from benchmark import (
        ROOT,
        make_reranker,
        map_results,
        metrics,
        percentile,
        select_models,
        skip_reason,
        write_results,
        load_gateway_environment,
    )
    from collect import FORMAT


def validate(data: dict) -> None:
    if data.get("format") != FORMAT or not data.get("queries"):
        raise ValueError("expected a captured evaluation snapshot")
    ids = [q["id"] for q in data["queries"]]
    if len(set(ids)) != len(ids):
        raise ValueError("duplicate query IDs")
    for query in data["queries"]:
        pool = query["candidates"]
        if not pool or len({c["id"] for c in pool}) != len(pool):
            raise ValueError("each query needs a nonempty unique candidate pool")
        if query["judgment_status"] not in ("pending", "complete"):
            raise ValueError("judgment_status must be pending or complete")
        for candidate in pool:
            if not isinstance(candidate["text"], str):
                raise ValueError("candidate text must be a string")
            grade = candidate["relevance"]
            if grade is not None and (type(grade) is not int or not 0 <= grade <= 3):
                raise ValueError("relevance must be null or an integer from 0 to 3")
            if query["judgment_status"] == "complete" and grade is None:
                raise ValueError(
                    "complete queries require every candidate to be judged"
                )


def evaluate(
    models,
    data,
    *,
    execute=False,
    allow_gateway=False,
    repetitions=5,
    warmups=1,
    max_remote_calls=0,
    factory=make_reranker,
    clock=perf_counter,
):
    import os

    validate(data)
    if not 5 <= repetitions <= 10 or warmups < 1 or max_remote_calls < 0:
        raise ValueError(
            "use 5–10 measured repetitions, at least one warmup, and a nonnegative cap"
        )
    # Never permit direct external providers for potentially private snapshots.
    for spec in models:
        if spec["provider"] != "local" and spec["provider"] != "litellm_proxy":
            raise ValueError(
                "private evaluation permits local models and the approved IxDLab gateway only"
            )
        if (
            spec["provider"] == "litellm_proxy"
            and spec.get("api_base_env") != "LITELLM_PROXY_API_BASE"
        ):
            raise ValueError("gateway route must use LITELLM_PROXY_API_BASE")
    report = {
        "calls": [],
        "errors": [],
        "skips": [],
        "summary": [],
        "warmups": [],
        "planned_remote_calls": sum(m["provider"] != "local" for m in models)
        * (warmups + len(data["queries"]) * repetitions),
        "remote_calls_attempted": 0,
        "query_count": len(data["queries"]),
        "judged_queries": sum(
            q["judgment_status"] == "complete" for q in data["queries"]
        ),
        "snapshot_sha256": hashlib.sha256(
            json.dumps(data, sort_keys=True).encode()
        ).hexdigest(),
    }
    for spec in models:
        remote = spec["provider"] != "local"
        reason = skip_reason(spec, execute, allow_gateway, dict(os.environ))
        if reason:
            report["skips"].append({"model": spec["name"], "reason": reason})
            continue
        if remote and report["remote_calls_attempted"] >= max_remote_calls:
            report["skips"].append(
                {"model": spec["name"], "reason": "remote call limit"}
            )
            continue
        try:
            adapter, initialization = factory(spec, False)
        except Exception as exc:
            report["errors"].append(
                {
                    "model": spec["name"],
                    "stage": "initialization",
                    "type": type(exc).__name__,
                }
            )
            continue

        def call(query):
            if remote:
                if report["remote_calls_attempted"] >= max_remote_calls:
                    return None
                report["remote_calls_attempted"] += 1
            pool = query["candidates"]
            response = adapter.rerank(
                query.get("rerank_query", query["query"]),
                [c["text"] for c in pool],
                top_n=len(pool),
            )
            return map_results(response, pool)

        warmed = True
        for _ in range(warmups):
            try:
                if call(data["queries"][0]) is None:
                    raise RuntimeError("warmup budget exhausted")
                report["warmups"].append({"model": spec["name"], "status": "ok"})
            except Exception as exc:
                report["errors"].append(
                    {
                        "model": spec["name"],
                        "stage": "warmup",
                        "type": type(exc).__name__,
                    }
                )
                warmed = False
                break
        if not warmed:
            continue
        successes, attempts, covered, judged_covered = [], 0, set(), set()
        for query in data["queries"]:
            pool = query["candidates"]
            for repetition in range(repetitions):
                if remote and report["remote_calls_attempted"] >= max_remote_calls:
                    report["skips"].append(
                        {
                            "model": spec["name"],
                            "query": query["id"],
                            "reason": "remote call limit",
                        }
                    )
                    continue
                attempts += 1
                started = clock()
                try:
                    ranking = call(query)
                    elapsed = clock() - started
                    row = {
                        "model": spec["name"],
                        "query": query["id"],
                        "repetition": repetition,
                        "latency_seconds": elapsed,
                        "candidate_count": len(pool),
                        "document_characters": [len(c["text"]) for c in pool],
                        "rankings": ranking,
                        "ndcg@5": None,
                        "ndcg@10": None,
                        "mrr@10": None,
                        "recall@10": None,
                    }
                    if query["judgment_status"] == "complete":
                        labels = {c["id"]: c["relevance"] for c in pool}
                        ranked_ids = [r["id"] for r in ranking]
                        row.update(metrics(ranked_ids, labels))
                        row["ndcg@5"] = metrics(ranked_ids, labels, 5)["ndcg@10"]
                        judged_covered.add(query["id"])
                    successes.append(row)
                    covered.add(query["id"])
                    report["calls"].append(row)
                except Exception as exc:
                    report["errors"].append(
                        {
                            "model": spec["name"],
                            "query": query["id"],
                            "stage": "measurement",
                            "type": type(exc).__name__,
                            "latency_seconds": clock() - started,
                        }
                    )
        report["summary"].append(
            {
                "model": spec["name"],
                "measured_attempts": attempts,
                "successful_calls": len(successes),
                "failure_rate": (attempts - len(successes)) / attempts
                if attempts
                else None,
                "query_coverage": len(covered),
                "judged_query_coverage": len(judged_covered),
                "initialization_seconds": initialization,
                "p50_seconds": percentile(
                    [s["latency_seconds"] for s in successes], 0.5
                ),
                "p95_seconds": percentile(
                    [s["latency_seconds"] for s in successes], 0.95
                ),
                **{
                    k: mean(s[k] for s in successes if s[k] is not None)
                    if any(s[k] is not None for s in successes)
                    else None
                    for k in ("ndcg@5", "ndcg@10", "mrr@10", "recall@10")
                },
            }
        )
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dataset", type=Path, required=True)
    parser.add_argument("--models", nargs="+", default=["local", "bge", "jina"])
    parser.add_argument("--execute", action="store_true")
    parser.add_argument("--allow-gateway", action="store_true")
    parser.add_argument("--env-file", type=Path)
    parser.add_argument("--repetitions", type=int, default=5)
    parser.add_argument("--warmups", type=int, default=1)
    parser.add_argument("--max-remote-calls", type=int, default=0)
    parser.add_argument("--output", type=Path, default=ROOT / "evaluation-results")
    args = parser.parse_args()
    if args.env_file:
        load_gateway_environment(args.env_file)
    models = select_models(json.loads((ROOT / "models.json").read_text()), args.models)
    data = json.loads(args.dataset.read_text())
    report = evaluate(
        models,
        data,
        execute=args.execute,
        allow_gateway=args.allow_gateway,
        repetitions=args.repetitions,
        warmups=args.warmups,
        max_remote_calls=args.max_remote_calls,
    )
    write_results(report, args.output)
    print(
        json.dumps(
            {
                k: report[k]
                for k in (
                    "query_count",
                    "judged_queries",
                    "planned_remote_calls",
                    "remote_calls_attempted",
                    "summary",
                )
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
