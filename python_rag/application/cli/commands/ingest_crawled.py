#!/usr/bin/env python3
"""CLI wrapper for crawling folder ingestion.

This module parses CLI arguments and delegates execution to :mod:`runner`.
The previous heavy orchestration logic was split out so this stays focused on CLI
concerns.
"""

from __future__ import annotations

import argparse
import os
import sys

from application.cli.commands.runner import run_ingest

EXIT_SUCCESS = 0
EXIT_RUNTIME_FAILURE = 1
EXIT_VALIDATION_FAILURE = 2
EXIT_PARTIAL_SUCCESS = 3


def _int_env(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    try:
        return int(raw)
    except ValueError:
        return default


def build_arg_parser() -> argparse.ArgumentParser:
    """Build CLI parser for the crawler ingest runner."""
    parser = argparse.ArgumentParser(description="Ingest local crawled-data into LightRAG via HTTP.")
    parser.add_argument("--root", required=True, help="Path to local crawled-data root")
    parser.add_argument("--base-url", default="http://localhost:8009", help="LightRAG base URL (default: http://localhost:8009)")
    parser.add_argument("--provider", default="ollama", help="Embedding/LLM provider name")
    parser.add_argument("--embedding-model", default=None, help="Embedding model override")
    parser.add_argument("--graph", action="store_true", help="Enable KG extraction during ingest")
    parser.add_argument("--graph-only", action="store_true", help="Skip Qdrant/embeddings and only write Neo4j triplets")
    parser.add_argument("--graph-engine", default="raganything", help="Graph engine")
    parser.add_argument("--graph-model", default=None, help="Graph LLM model override")
    parser.add_argument("--neo4j-database", default=None, help="Neo4j database name")
    parser.add_argument("--collection", default=None, help="Qdrant collection override")
    parser.add_argument("--distance", default="Cosine", help="Qdrant distance (Cosine|Dot|Euclid)")
    parser.add_argument("--chunk-chars", type=int, default=_int_env("CHUNK_SIZE", 1200), help="Chunk target size for LightRAG")
    parser.add_argument("--chunk-overlap", type=int, default=_int_env("CHUNK_OVERLAP_SIZE", 100), help="Chunk overlap for LightRAG")
    parser.add_argument("--batch", type=int, default=64, help="POST batch size (docs per request)")
    parser.add_argument("--timeout", type=int, default=1800, help="HTTP request timeout in seconds")
    parser.add_argument("--dry", action="store_true", help="Dry-run preview without writes")
    parser.add_argument("--summary-file", default=None, help="Optional path to save the ingest summary JSON")
    parser.add_argument("--dry-include-graph", action="store_true", help="When used with --dry, also estimate Neo4j graph impact")
    parser.add_argument("--estimate-only", action="store_true", help="Estimate chunk/point counts locally")
    parser.add_argument(
        "--resume-state-dir",
        default=os.environ.get("HAWKI_RAG_INGEST_RESUME_STATE_DIR", "storage/app/private/ingest-state"),
        help="Directory where resume markers are stored",
    )
    parser.add_argument("--resume", action="store_true", help="Resume by skipping already ingested docs")
    parser.add_argument("--start", action="store_true", help="Start fresh and ignore previous state")
    return parser


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    """Parse CLI args from a testable input vector."""
    return build_arg_parser().parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    """Run ingestion and return the runner exit status."""
    args = parse_args(argv)
    return run_ingest(args)


def _reexport_metadata_helpers() -> None:
    """Expose helper imports used by CLI-adjacent call sites."""

    from application.cli.commands.discovery import discover_page_dirs
    from application.cli.commands.materials import load_page_materials
    from application.cli.commands.metadata import (
        first_str,
        make_doc_id,
        resolve_date,
        resolve_tags,
        title_from_markdown,
        to_array_list,
    )
    from application.cli.commands.payloads import build_bridge_doc, build_payload
    from application.cli.commands.url_maps import build_url_maps, resolve_url_for_path
    from application.cli.commands.submit import post_batch, write_summary_file

    globals().update(
        {
            "discover_page_dirs": discover_page_dirs,
            "load_page_materials": load_page_materials,
            "first_str": first_str,
            "make_doc_id": make_doc_id,
            "resolve_date": resolve_date,
            "resolve_tags": resolve_tags,
            "title_from_markdown": title_from_markdown,
            "to_array_list": to_array_list,
            "build_bridge_doc": build_bridge_doc,
            "build_payload": build_payload,
            "build_url_maps": build_url_maps,
            "resolve_url_for_path": resolve_url_for_path,
            "post_batch": post_batch,
            "write_summary_file": write_summary_file,
        }
    )


_reexport_metadata_helpers()

__all__ = [
    "build_arg_parser",
    "parse_args",
    "main",
    "run_ingest",
    "build_bridge_doc",
    "build_payload",
    "build_url_maps",
    "discover_page_dirs",
    "first_str",
    "load_page_materials",
    "make_doc_id",
    "post_batch",
    "resolve_date",
    "resolve_tags",
    "resolve_url_for_path",
    "title_from_markdown",
    "to_array_list",
    "write_summary_file",
]


if __name__ == "__main__":
    sys.exit(main())
