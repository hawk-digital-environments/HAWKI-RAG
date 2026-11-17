"""synthetic workload and stepped concurrency plan."""

from collections.abc import Callable
import json
import math
import sys
from time import sleep


TOPICS = (
    "dataset deletion",
    "embedding retrieval",
    "answer generation",
    "graph extraction",
    "access control",
    "pipeline monitoring",
)


def synthetic_load_dataset() -> dict:
    """Generate longer synthetic documents without relevance judgments."""
    candidates = []
    for topic in TOPICS:
        for i in range(4):
            filler = " ".join(
                f"{topic} aspect {i}.{k} covers how the {topic} stage of the HAWKI-RAG "
                f"retrieval pipeline validates inputs, coordinates workers, and records "
                f"progress for operators reviewing the run."
                for k in range(8)
            )
            candidates.append(
                {
                    "id": f"doc-{topic.replace(' ', '-')}-{i}",
                    "payload": {
                        "content": f"{topic.capitalize()} document {i}. {filler}"
                    },
                }
            )
    return {
        "candidates": candidates,
        "queries": [
            {
                "id": topic.replace(" ", "-"),
                "query": f"How does HAWKI-RAG handle {topic} during ingestion and query processing?",
            }
            for topic in TOPICS
        ],
    }


def load_sweep(
    models: list[dict],
    *,
    levels: list[int],
    per: int,
    cooldown: float,
    max_remote_calls: int,
    run_benchmark: Callable[..., dict],
    factory: Callable,
    execute: bool = False,
    allow_remote: bool = False,
    on_phase: Callable[[dict], None] | None = None,
) -> dict:
    """Run N*per requests at each level, retaining one client per remote model."""
    if not levels or any(level < 1 for level in levels) or per < 1:
        raise ValueError("levels and --per must be positive")
    if not math.isfinite(cooldown) or cooldown < 0:
        raise ValueError("--cooldown must be finite and non-negative")
    if any(model["provider"] == "local" for model in models):
        raise ValueError("Load tests support remote models only; select --models jina")
    planned = sum(level * per for level in levels) * sum(
        bool(model.get("model")) and not model.get("skip") for model in models
    )
    if max_remote_calls < planned:
        raise ValueError(f"Full sweep needs --max-remote-calls {planned} or higher")
    data = synthetic_load_dataset()
    lengths = [len(item["payload"]["content"]) for item in data["candidates"]]
    report = {
        "mode": "load_test",
        "payload": {
            "documents": len(lengths),
            "queries": len(data["queries"]),
            "document_characters": sum(lengths),
            "average_document_characters": sum(lengths) / len(lengths),
        },
        "levels": levels,
        "per": per,
        "cooldown_seconds": cooldown,
        "planned_remote_calls": planned,
        "remote_calls_attempted": 0,
        "calls": [],
        "errors": [],
        "skips": [],
        "summary": [],
    }
    print(
        f"Load-test plan: {planned} rerank requests; levels={levels}; per={per}; "
        f"24 documents/request, {sum(lengths)} document characters/request; "
        f"cooldown={cooldown}s.",
        file=sys.stderr,
        flush=True,
    )
    clients = {}

    def cached_factory(spec, allow_download):
        name = spec["name"]
        if name not in clients:
            clients[name] = factory(spec, allow_download)
            return clients[name]
        return clients[name][0], None

    phase_index = 0
    for model in models:
        for level in levels:
            if report["remote_calls_attempted"] and execute and allow_remote:
                sleep(cooldown)
            phase = run_benchmark(
                [model],
                data,
                execute=execute,
                allow_remote=allow_remote,
                concurrency=level,
                requests_per_model=level * per,
                worker_requests=per,
                max_remote_calls=max_remote_calls - report["remote_calls_attempted"],
                factory=cached_factory,
                progress=execute and allow_remote,
            )
            report["remote_calls_attempted"] += phase["remote_calls_attempted"]
            token_counts = []
            for call in phase["calls"]:
                tokens = (call.get("usage") or {}).get("tokens")
                value = tokens.get("input_tokens") if isinstance(tokens, dict) else None
                if type(value) is int and value >= 0:
                    token_counts.append(value)
            for row in phase["summary"]:
                row["reported_input_tokens"] = (
                    sum(token_counts) if token_counts else None
                )
                row["input_token_usage_calls"] = len(token_counts)
            for section in ("calls", "errors", "skips", "summary"):
                for row in phase[section]:
                    report[section].append(
                        {**row, "concurrency": level, "phase": phase_index}
                    )
            phase_index += 1
            if on_phase:
                on_phase(report)
            if phase["summary"]:
                print(json.dumps(report["summary"][-1]), flush=True)
    return report
