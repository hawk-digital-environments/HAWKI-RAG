"""Completion reporting and exit policy for retry ingest."""

from __future__ import annotations

import sys

EXIT_SUCCESS = 0
EXIT_RUNTIME_FAILURE = 1
EXIT_PARTIAL_SUCCESS = 3


def report_retry_completion(
    *,
    requested_doc_ids: set[str],
    matched: dict[str, str],
    remaining: set[str],
    failures: int,
) -> int:
    """Print retry ingest completion details and return the command exit code."""

    print(f"Matched {len(matched)} of {len(requested_doc_ids)} requested documents.")
    if remaining:
        print("The following doc IDs were not found in the crawled data:", file=sys.stderr)
        for missing in sorted(remaining):
            print(f"  - {missing}", file=sys.stderr)

    if failures:
        print(f"{failures} batch(es) failed during re-ingest. Check the logs above.", file=sys.stderr)
        return EXIT_RUNTIME_FAILURE
    if remaining:
        return EXIT_PARTIAL_SUCCESS
    return EXIT_SUCCESS


__all__ = [
    "EXIT_PARTIAL_SUCCESS",
    "EXIT_RUNTIME_FAILURE",
    "EXIT_SUCCESS",
    "report_retry_completion",
]
