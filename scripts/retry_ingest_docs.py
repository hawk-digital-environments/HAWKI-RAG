#!/usr/bin/env python3
"""
Utility CLI to re-ingest a specific set of document IDs into the LightRAG service.

The script reuses the crawling helpers from `ingest_crawled.py` to rebuild the
payloads for the requested documents and posts them to the configured LightRAG
instance in controlled batches.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Set, Tuple

from ingest_crawled import (
    build_url_maps,
    discover_page_dirs,
    load_page_materials,
    resolve_url_for_path,
    make_doc_id,
    first_str,
    resolve_tags,
    title_from_markdown,
    post_batch,
)


def _load_doc_ids_from_file(path: Path) -> Set[str]:
    """
    Load doc IDs from a text or JSON file.

    - Plain text: whitespace/comma separated tokens.
    - JSON: either a list of strings or an object containing a 'doc_ids' array.
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
    return {doc_id.strip().lower() for doc_id in doc_ids if str(doc_id).strip()}


def _normalize_doc_ids(values: Iterable[str]) -> Set[str]:
    out: Set[str] = set()
    for value in values:
        if not value:
            continue
        out.add(str(value).strip().lower())
    return out


def _build_options(args: argparse.Namespace) -> Dict[str, object]:
    options: Dict[str, object] = {
        "provider": args.provider,
        "graph": bool(args.graph),
        "graph_engine": args.graph_engine,
        "distance": args.distance,
        "chunk_chars": int(args.chunk_chars),
        "chunk_overlap": int(args.chunk_overlap),
    }
    if args.collection:
        options["collection"] = args.collection
    if args.dry:
        options["dry_run"] = True
        if args.dry_include_graph:
            options["dry_include_graph"] = True
    return options


def _queue_doc(
    directory: Path,
    root: Path,
    page_url_map: Dict[Path, str],
    source_url_map: Dict[Path, str],
) -> Optional[Tuple[Dict[str, object], str]]:
    meta, _, _, text, source_fmt = load_page_materials(directory)
    if not isinstance(text, str) or not text.strip():
        return None

    title = first_str(meta.get("title")) or (title_from_markdown(text) or "Untitled")
    dir_resolved = directory.resolve(strict=False)
    page_url = first_str(meta.get("url") or meta.get("page_url")) or resolve_url_for_path(page_url_map, dir_resolved, root)
    date = first_str(meta.get("date"))
    meta_img = first_str(meta.get("metaImageUrl") or meta.get("meta_img_url"))
    tags = resolve_tags(meta, text)
    rel = str(directory.relative_to(root))
    source_url = first_str(meta.get("source_url")) or resolve_url_for_path(source_url_map, dir_resolved, root)
    if not source_url and page_url:
        source_url = page_url
    doc_id = make_doc_id(source_url if source_url and source_url != page_url else page_url, rel)

    payload = {
        "title": title,
        "page_url": page_url or rel,
        "source_url": source_url or page_url or rel,
        "date": date,
        "meta_img_url": meta_img,
        "tags": tags or None,
        "source_format": source_fmt,
    }

    doc: Dict[str, object] = {
        "id": doc_id,
        "text": text,
        "payload": payload,
    }
    return doc, doc_id


def run(args: argparse.Namespace) -> int:
    root = Path(args.root).expanduser().resolve()
    if not root.exists() or not root.is_dir():
        print(f"Root not found or not a directory: {root}", file=sys.stderr)
        return 2

    requested_doc_ids: Set[str] = _normalize_doc_ids(args.doc_ids or [])
    if args.doc_ids_file:
        file_ids = _load_doc_ids_from_file(Path(args.doc_ids_file).expanduser().resolve())
        requested_doc_ids.update(file_ids)

    if not requested_doc_ids:
        print("No doc IDs provided. Use --doc-id or --doc-ids-file.", file=sys.stderr)
        return 2

    options = _build_options(args)
    page_url_map, source_url_map = build_url_maps(root)
    page_dirs = discover_page_dirs(root)
    if not page_dirs:
        print("No candidate page directories found under the specified root.", file=sys.stderr)
        return 1

    remaining = set(requested_doc_ids)
    matched: Dict[str, str] = {}
    docs: List[Dict[str, object]] = []
    sent = 0
    batch_index = 0
    failures = 0

    print(f"Scanning {root} for {len(requested_doc_ids)} requested document(s)…")
    for directory in page_dirs:
        queued = _queue_doc(directory, root, page_url_map, source_url_map)
        if queued is None:
            continue
        doc, doc_id = queued
        key = doc_id.lower()
        if key not in remaining:
            continue

        docs.append(doc)
        matched[key] = str(directory)
        remaining.remove(key)

        if len(docs) >= args.batch:
            batch_index += 1
            doc_ids_batch = [d["id"] for d in docs]
            ok, _, err = post_batch(args.base_url, docs, options, timeout=args.timeout)
            if ok:
                sent += len(docs)
                status_msg = "Planned" if args.dry else "Sent"
                print(f"{status_msg} {sent} docs (batch {batch_index})")
            else:
                failures += 1
                print(f"Batch {batch_index} failed; docs={doc_ids_batch} ({err or 'see log'})", file=sys.stderr)
            docs = []

        if not remaining:
            break

    if docs:
        batch_index += 1
        doc_ids_batch = [d["id"] for d in docs]
        ok, _, err = post_batch(args.base_url, docs, options, timeout=args.timeout)
        if ok:
            sent += len(docs)
            status_msg = "Planned" if args.dry else "Sent"
            print(f"{status_msg} {sent} docs (batch {batch_index})")
        else:
            failures += 1
            print(f"Batch {batch_index} failed; docs={doc_ids_batch} ({err or 'see log'})", file=sys.stderr)

    matched_count = len(matched)
    print(f"Matched {matched_count} of {len(requested_doc_ids)} requested documents.")
    if remaining:
        print("The following doc IDs were not found in the crawled data:", file=sys.stderr)
        for missing in sorted(remaining):
            print(f"  - {missing}", file=sys.stderr)

    if failures:
        print(f"{failures} batch(es) failed during re-ingest. Check the logs above.", file=sys.stderr)
        return 1
    return 0


def parse_args(argv: Optional[List[str]] = None) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description="Re-ingest specific document IDs into LightRAG.")
    ap.add_argument("--root", required=True, help="Path to the crawled-data root that was used for the original ingest.")
    ap.add_argument("--base-url", default="http://localhost:8009", help="LightRAG base URL (default: http://localhost:8009)")
    ap.add_argument("--provider", default="ollama", help="Embedding/LLM provider name.")
    ap.add_argument("--graph", action="store_true", help="Enable KG extraction during ingest.")
    ap.add_argument("--graph-engine", default="lightrag", help="Graph engine to use when --graph is enabled.")
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
    return ap.parse_args(argv)


def main(argv: Optional[List[str]] = None) -> None:
    args = parse_args(argv)
    rc = run(args)
    raise SystemExit(rc)


if __name__ == "__main__":
    main()
