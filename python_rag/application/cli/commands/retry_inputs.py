"""Input parsing and option shaping for retry-ingest CLI."""

from __future__ import annotations

import argparse
import json
import re
from collections.abc import Iterable
from pathlib import Path
from typing import Any


def load_doc_ids_from_file(path: Path) -> set[str]:
    """
    Load doc IDs from a text or JSON file.

    - Plain text: whitespace/comma separated tokens.
    - JSON: either a list of strings or an object containing a ``doc_ids`` array.
    """

    if not path.exists():
        raise FileNotFoundError(f"Doc ID file not found: {path}")
    raw = path.read_text(encoding="utf-8").strip()
    if not raw:
        return set()
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        tokens = re.split(r"[\s,]+", raw)
        return {tok.strip().lower() for tok in tokens if tok.strip()}

    doc_ids: Iterable[str] = []
    if isinstance(data, list):
        doc_ids = [str(item) for item in data]
    elif isinstance(data, dict) and isinstance(data.get("doc_ids"), list):
        doc_ids = [str(item) for item in data["doc_ids"]]
    else:
        raise ValueError(f"Unsupported JSON structure in {path}; expected list or object with 'doc_ids'.")
    return normalize_doc_ids(doc_ids)


def load_doc_ids_from_failures(path: Path) -> set[str]:
    """Load doc IDs from a JSONL failures log using doc_id, id, or docId keys."""

    if not path.exists():
        raise FileNotFoundError(f"Failures file not found: {path}")
    doc_ids: set[str] = set()
    for line in path.read_text(encoding="utf-8").splitlines():
        raw = line.strip()
        if not raw:
            continue
        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            continue
        if not isinstance(data, dict):
            continue
        for key in ("doc_id", "id", "docId"):
            value = data.get(key)
            if value:
                doc_ids.add(str(value).strip().lower())
                break
    return doc_ids


def normalize_doc_ids(values: Iterable[str]) -> set[str]:
    """Normalize user-provided doc IDs for case-insensitive matching."""

    out: set[str] = set()
    for value in values:
        if not value:
            continue
        out.add(str(value).strip().lower())
    return out


def build_retry_options(args: argparse.Namespace) -> dict[str, object]:
    """Build the ingest API option payload for retry-ingest requests."""

    options: dict[str, object] = {
        "provider": args.provider,
        "graph": bool(args.graph),
        "graph_engine": args.graph_engine,
        "distance": args.distance,
        "chunk_chars": int(args.chunk_chars),
        "chunk_overlap": int(args.chunk_overlap),
    }
    if args.graph_only:
        options["graph"] = True
        options["graph_only"] = True
    if args.collection:
        options["collection"] = args.collection
    if args.dry:
        options["dry_run"] = True
        if args.dry_include_graph:
            options["dry_include_graph"] = True
    return options


__all__ = [
    "build_retry_options",
    "load_doc_ids_from_failures",
    "load_doc_ids_from_file",
    "normalize_doc_ids",
]
