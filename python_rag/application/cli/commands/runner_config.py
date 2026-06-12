"""Configuration and summary helpers for crawl-ingest runner."""

from __future__ import annotations

import os
from collections.abc import Mapping
from typing import Any

from application.cli.commands.estimation import utc_now_iso


def env_bool(name: str, default: bool = False, *, env: Mapping[str, str] | None = None) -> bool:
    """Read a boolean environment flag using the runner's legacy truthy values."""
    env_map = env or os.environ
    raw = env_map.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    return str(raw).strip().lower() in {"1", "true", "yes", "on"}


def env_choice(
    name: str,
    allowed: set[str],
    default: str,
    *,
    env: Mapping[str, str] | None = None,
) -> str:
    """Read a constrained environment choice with a clear validation error."""
    env_map = env or os.environ
    raw = env_map.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    value = str(raw).strip().lower()
    if value not in allowed:
        raise ValueError(
            f"Invalid {name}={raw!r}; expected one of: {', '.join(sorted(allowed))}."
        )
    return value


def build_default_options(args: Any) -> dict[str, Any]:
    """Build the bridge API options payload from parsed CLI args."""
    options: dict[str, Any] = {
        "provider": args.provider,
        "graph": bool(args.graph),
        "graph_engine": args.graph_engine,
        "distance": args.distance,
        "chunk_chars": int(args.chunk_chars),
        "chunk_overlap": int(args.chunk_overlap),
        "batch_size": int(args.batch),
    }
    if args.graph_model:
        options["graph_model"] = args.graph_model
    if args.graph_only:
        options["graph_only"] = True
    if args.neo4j_database:
        options["neo4j_database"] = args.neo4j_database
    if args.embedding_model:
        options["embedding_model"] = args.embedding_model
    if args.collection:
        options["collection"] = args.collection
    if args.dry:
        options["dry_run"] = True
        if args.dry_include_graph:
            options["dry_include_graph"] = True
    return options


def build_no_pages_summary(summary_file: str | None) -> dict[str, Any]:
    """Build the partial summary returned when no page folders are found."""
    return {
        "timestamp": utc_now_iso(),
        "estimate_only": False,
        "status": "partial",
        "reason": "no_pages_found",
        "documents": {
            "total_docs": 0,
            "processed_docs": 0,
            "skipped_docs": 0,
            "empty_docs": 0,
            "doc_ids": [],
            "total_chunks": 0,
        },
        "summary_file": summary_file,
    }


def build_no_ingestable_summary(
    total_dirs: int,
    skipped_empty: int,
    skipped_empty_paths: list[str],
    summary_file: str | None,
) -> dict[str, Any]:
    """Build the partial summary returned when all discovered pages are empty."""
    return {
        "timestamp": utc_now_iso(),
        "estimate_only": False,
        "status": "partial",
        "reason": "no_ingestable_documents",
        "documents": {
            "total_docs": total_dirs,
            "processed_docs": 0,
            "skipped_docs": skipped_empty,
            "empty_docs": skipped_empty,
            "empty_paths": skipped_empty_paths,
            "doc_ids": [],
            "total_chunks": 0,
        },
        "summary_file": summary_file,
    }


__all__ = [
    "build_default_options",
    "build_no_ingestable_summary",
    "build_no_pages_summary",
    "env_bool",
    "env_choice",
]
