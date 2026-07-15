#!/usr/bin/env python3
"""
Utility CLI to re-ingest a specific set of document IDs into the LightRAG service.

The script reuses the crawling helpers from `ingest_crawled.py` to rebuild the
payloads for the requested documents and posts them to the configured LightRAG
instance in controlled batches.
"""

from __future__ import annotations

import argparse
import sys
import logging
from pathlib import Path
from typing import Optional


def _ensure_python_rag_package() -> None:
    """Add repository root to ``sys.path`` for direct script execution."""
    current_file = Path(__file__).resolve()
    for parent in [current_file, *current_file.parents]:
        if (parent / "application").is_dir() and (parent / "shared").is_dir():
            root = str(parent)
            if root not in sys.path:
                sys.path.insert(0, root)
            return
    fallback_root = str(current_file.parents[3]) if len(current_file.parents) > 3 else str(current_file.parent)
    if fallback_root not in sys.path:
        sys.path.insert(0, fallback_root)
try:
    from application.cli.commands.discovery import discover_page_dirs
    from application.cli.commands.retry_batches import RetryBatchSender
    from application.cli.commands.retry_completion import report_retry_completion
    from application.cli.commands.retry_documents import queue_retry_doc
    from application.cli.commands.retry_inputs import (
        build_retry_options,
        load_doc_ids_from_failures,
        load_doc_ids_from_file,
        normalize_doc_ids,
    )
    from application.cli.commands.submit import post_batch
    from application.cli.commands.url_maps import build_url_maps
except ModuleNotFoundError:  # pragma: no cover - legacy direct script execution
    _ensure_python_rag_package()
    from application.cli.commands.discovery import discover_page_dirs
    from application.cli.commands.retry_batches import RetryBatchSender
    from application.cli.commands.retry_completion import report_retry_completion
    from application.cli.commands.retry_documents import queue_retry_doc
    from application.cli.commands.retry_inputs import (
        build_retry_options,
        load_doc_ids_from_failures,
        load_doc_ids_from_file,
        normalize_doc_ids,
    )
    from application.cli.commands.submit import post_batch
    from application.cli.commands.url_maps import build_url_maps

logger = logging.getLogger(__name__)

EXIT_SUCCESS = 0
EXIT_RUNTIME_FAILURE = 1
EXIT_VALIDATION_FAILURE = 2
EXIT_PARTIAL_SUCCESS = 3


def run(args: argparse.Namespace) -> int:
    root = Path(args.root).expanduser().resolve()
    if not root.exists() or not root.is_dir():
        print(f"Root not found or not a directory: {root}", file=sys.stderr)
        return EXIT_VALIDATION_FAILURE

    requested_doc_ids: set[str] = normalize_doc_ids(args.doc_ids or [])
    if args.doc_ids_file:
        file_ids = load_doc_ids_from_file(Path(args.doc_ids_file).expanduser().resolve())
        requested_doc_ids.update(file_ids)
    if args.failures_file:
        failure_ids = load_doc_ids_from_failures(Path(args.failures_file).expanduser().resolve())
        requested_doc_ids.update(failure_ids)

    if not requested_doc_ids:
        print("No doc IDs provided. Use --doc-id, --doc-ids-file, or --failures-file.", file=sys.stderr)
        return EXIT_VALIDATION_FAILURE

    options = build_retry_options(args)
    page_url_map, source_url_map = build_url_maps(root)
    page_dirs = discover_page_dirs(root)
    if not page_dirs:
        print("No candidate page directories found under the specified root.", file=sys.stderr)
        return EXIT_PARTIAL_SUCCESS

    remaining = set(requested_doc_ids)
    matched: dict[str, str] = {}
    docs: list[dict[str, object]] = []
    batch_sender = RetryBatchSender(
        args=args,
        options=options,
        post_batch=post_batch,
        logger_obj=logger,
    )

    print(f"Scanning {root} for {len(requested_doc_ids)} requested document(s)…")
    for directory in page_dirs:
        queued = queue_retry_doc(
            directory=directory,
            root=root,
            page_url_map=page_url_map,
            source_url_map=source_url_map,
        )
        if queued is None:
            continue
        doc = queued.doc
        doc_id = queued.doc_id
        key = doc_id.lower()
        if key not in remaining:
            continue

        docs.append(doc)
        matched[key] = str(directory)
        remaining.remove(key)

        if len(docs) >= args.batch:
            batch_sender.send(docs)
            docs = []

        if not remaining:
            break

    if docs:
        batch_sender.send(docs)

    return report_retry_completion(
        requested_doc_ids=requested_doc_ids,
        matched=matched,
        remaining=remaining,
        failures=batch_sender.failures,
    )


def parse_args(argv: Optional[list[str]] = None) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description="Re-ingest specific document IDs into LightRAG.")
    ap.add_argument("--root", required=True, help="Path to the crawled-data root that was used for the original ingest.")
    ap.add_argument("--base-url", default="http://localhost:8009", help="LightRAG base URL (default: http://localhost:8009)")
    ap.add_argument("--provider", default="litellm", help="Embedding/LLM provider name.")
    ap.add_argument("--graph", action="store_true", help="Enable KG extraction during ingest.")
    ap.add_argument("--graph-only", action="store_true", help="Skip embeddings and only write Neo4j triplets.")
    ap.add_argument("--graph-engine", default="raganything", help="Graph engine to use when --graph is enabled.")
    ap.add_argument("--collection", default=None, help="Qdrant collection override.")
    ap.add_argument("--distance", default="Cosine", help="Qdrant distance (Cosine|Dot|Euclid).")
    ap.add_argument("--chunk-chars", type=int, default=3200, help="Chunk target size for LightRAG.")
    ap.add_argument("--chunk-overlap", type=int, default=100, help="Chunk overlap for LightRAG.")
    ap.add_argument("--batch", type=int, default=32, help="POST batch size (docs per request).")
    ap.add_argument("--timeout", type=int, default=1800, help="HTTP request timeout in seconds.")
    ap.add_argument("--dry", action="store_true", help="Perform a dry run to preview Qdrant/Neo4j impact without embeddings.")
    ap.add_argument("--dry-include-graph", action="store_true", help="When used with --dry, also estimate Neo4j entities/relationships.")
    ap.add_argument("--doc-id", dest="doc_ids", action="append", default=[], help="Doc ID to re-ingest (can be specified multiple times).")
    ap.add_argument("--doc-ids-file", help="Path to a file containing doc IDs (newline/comma separated or JSON).")
    ap.add_argument("--failures-file", help="Path to a JSONL failures file (e.g. ingest_graph_failures.jsonl).")
    return ap.parse_args(argv)


def main(argv: Optional[list[str]] = None) -> None:
    args = parse_args(argv)
    rc = run(args)
    raise SystemExit(rc)


if __name__ == "__main__":
    main()
