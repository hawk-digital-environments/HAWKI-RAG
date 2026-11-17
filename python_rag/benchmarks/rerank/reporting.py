"""JSON/CSV exports and console summaries for reranking benchmarks."""

import csv
import json
from pathlib import Path


def write_results(report: dict, output: Path) -> None:
    output.mkdir(parents=True, exist_ok=True)
    (output / "results.json").write_text(
        json.dumps(report, indent=2, allow_nan=False) + "\n", encoding="utf-8"
    )
    if "qrels" in report:
        (output / "qrels.json").write_text(
            json.dumps(report["qrels"], indent=2) + "\n", encoding="utf-8"
        )
        runs = {}
        for call in report["calls"]:
            run = runs.setdefault(
                f"{call['model']}:repetition:{call['repetition']}", {}
            )
            ranking = call["rankings"]
            run[call["query"]] = {
                r["id"]: len(ranking) - rank for rank, r in enumerate(ranking)
            }
        (output / "runs.json").write_text(
            json.dumps(runs, indent=2) + "\n", encoding="utf-8"
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


def print_report(report: dict, output: Path) -> None:
    print(
        f"Planned remote calls (before key/permission checks): {report['planned_remote_calls']}; "
        f"attempted: {report['remote_calls_attempted']}"
    )
    if report.get("mode") == "load_test":
        print(f"Payload: {report['payload']}")
        print(
            "Model          Conc  Peak  Calls  OK  Fail   Req/s  P50(s) P95(s) Max(s) Input tokens"
        )
        for row in report["summary"]:
            latency = " ".join(
                "unknown" if row[key] is None else f"{row[key]:.3f}"
                for key in ("all_p50_seconds", "all_p95_seconds", "max_seconds")
            )
            print(
                f"{row['model']:<14} {row['concurrency']:>4} {row['peak_in_flight']:>5} "
                f"{row['attempted_calls']:>6} {row['successful_calls']:>3} {row['failed_calls']:>5} "
                f"{row['attempted_calls_per_second']:.2f} {latency} "
                f"{row['reported_input_tokens']} errors={row['error_counts']}"
            )
        print(
            f"Errors: {len(report['errors'])}; skips: {len(report['skips'])}; output: {output}"
        )
        return
    print("Model             Calls   P50(s)   P95(s)   nDCG@10   MRR@10   Recall@10")
    for row in report["summary"]:
        values = [
            "unknown" if row[k] is None else f"{row[k]:.4f}"
            for k in ("p50_seconds", "p95_seconds", "ndcg@10", "mrr@10", "recall@10")
        ]
        print(f"{row['model']:<17} {row['successful_calls']:>5} " + " ".join(values))
        print(
            f"  concurrency={row['concurrency']} peak={row['peak_in_flight']} "
            f"attempted={row['attempted_calls']} failed={row['failed_calls']} "
            f"successful_calls/s={row['successful_calls_per_second']:.2f}"
        )
    print(
        f"Errors: {len(report['errors'])}; skips: {len(report['skips'])}; output: {output}"
    )
