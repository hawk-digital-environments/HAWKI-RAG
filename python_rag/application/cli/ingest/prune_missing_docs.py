"""
Prune documents that no longer appear in a crawl folder by deleting their doc_ids
from Qdrant + Neo4j through the FastAPI bridge.

Doc IDs are computed the same way as ingest_crawled.py: sha1(page_url or rel_path).
"""
from __future__ import annotations

import argparse
import hashlib
import sys
from pathlib import Path
from typing import Set

import requests

# Reuse helpers from ingest_crawled for consistent doc_id generation
from ingest_crawled import discover_page_dirs, load_page_materials, first_str, make_doc_id  # type: ignore

EXIT_SUCCESS = 0
EXIT_RUNTIME_FAILURE = 1
EXIT_VALIDATION_FAILURE = 2
EXIT_PARTIAL_SUCCESS = 3


def collect_doc_ids(root: Path) -> set[str]:
    ids: set[str] = set()
    page_dirs = discover_page_dirs(root)
    for d in page_dirs:
        meta, _, _, _, _ = load_page_materials(d)
        page_url = first_str(meta.get("url") or meta.get("page_url"))
        rel = str(d.relative_to(root))
        doc_id = make_doc_id(page_url, rel)
        ids.add(doc_id)
    return ids


def fetch_existing_ids(qdrant_url: str, collection: str) -> set[str]:
    ids: set[str] = set()
    next_offset = None
    url = qdrant_url.rstrip("/") + f"/collections/{collection}/points/scroll"
    while True:
        payload = {
            "limit": 1000,
            "with_payload": False,
            "with_vectors": False,
        }
        if next_offset is not None:
            payload["offset"] = next_offset
        resp = requests.post(url, json=payload, timeout=30)
        resp.raise_for_status()
        data = resp.json()
        points = data.get("result", {}).get("points", [])
        for p in points:
            pid = p.get("id")
            if pid is not None:
                ids.add(str(pid))
        next_offset = data.get("result", {}).get("next_page_offset")
        if not next_offset:
            break
    return ids


def delete_missing(base_url: str, doc_ids: set[str], dry_run: bool) -> int:
    failures = 0
    session = requests.Session()
    for idx, doc_id in enumerate(sorted(doc_ids), start=1):
        if dry_run:
            print(f"[dry-run] would delete {doc_id}")
            continue
        resp = session.delete(base_url.rstrip("/") + f"/documents/{doc_id}", timeout=30)
        if resp.status_code >= 300:
            print(f"[warn] delete {doc_id} failed ({resp.status_code}): {resp.text}", file=sys.stderr)
            failures += 1
        else:
            print(f"[{idx}/{len(doc_ids)}] deleted {doc_id}")
    return failures


def main() -> int:
    ap = argparse.ArgumentParser(description="Prune documents no longer present in a crawl folder.")
    ap.add_argument("--root", required=True, help="Path to crawl/output folder.")
    ap.add_argument("--collection", required=True, help="Qdrant collection name to prune.")
    ap.add_argument("--base-url", default="http://localhost:8009", help="FastAPI bridge base URL.")
    ap.add_argument("--qdrant-url", default="http://localhost:6333", help="Qdrant base URL.")
    ap.add_argument("--dry-run", action="store_true", help="List deletions without performing them.")
    args = ap.parse_args()

    root = Path(args.root).expanduser().resolve()
    if not root.exists():
        print(f"root not found: {root}", file=sys.stderr)
        return EXIT_VALIDATION_FAILURE

    desired = collect_doc_ids(root)
    print(f"Found {len(desired)} doc_ids in crawl folder")

    existing = fetch_existing_ids(args.qdrant_url, args.collection)
    print(f"Found {len(existing)} doc_ids in Qdrant collection '{args.collection}'")

    to_delete = existing - desired
    print(f"Will delete {len(to_delete)} stale doc_ids")

    failures = delete_missing(args.base_url, to_delete, args.dry_run)
    if failures:
        print(f"{failures} stale document delete(s) failed.", file=sys.stderr)
        return EXIT_RUNTIME_FAILURE
    if to_delete:
        return EXIT_PARTIAL_SUCCESS
    return EXIT_SUCCESS


if __name__ == "__main__":
    sys.exit(main())
