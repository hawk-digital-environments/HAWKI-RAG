#!/usr/bin/env python3
"""
Lightweight CLI to replay crawled documents directly into the LightRAG server
via the /documents/texts endpoint so the bundled UI/graph reflect the data.
+++++
The script mirrors the directory walking logic from ingest_crawled.py but
posts raw documents to LightRAG instead of the custom /ingest bridge.
"""
import argparse
import json
import os
import sys
from pathlib import Path
from typing import Dict, List, Tuple, Optional

try:
    import requests
except ImportError:
    print("This script requires 'requests'. Install with: pip install requests", file=sys.stderr)
    sys.exit(1)

def read_text_file(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8", errors="ignore")
    except Exception:
        try:
            return path.read_text(errors="ignore")
        except Exception:
            return ""

def load_materials(dir_path: Path) -> Tuple[Dict, str]:
    meta: Dict = {}
    text = ""

    for entry in dir_path.iterdir():
        if entry.is_file() and entry.suffix.lower() == ".json":
            try:
                meta = json.loads(entry.read_text(encoding="utf-8", errors="ignore"))
            except Exception:
                meta = {}
            break

    for entry in dir_path.iterdir():
        if entry.is_file() and entry.suffix.lower() in {".md", ".txt"}:
            text = read_text_file(entry).strip()
            break

    if not text and meta:
        c = meta.get("content") or meta.get("text") or ""
        text = str(c).strip()
    return meta, text

def discover_page_dirs(root: Path) -> List[Path]:
    out: List[Path] = []
    for dp, _, files in os.walk(root):
        p = Path(dp)
        suffixes = {Path(f).suffix.lower() for f in files}
        if any(s in suffixes for s in (".json", ".md", ".txt")):
            out.append(p)
    return out

def first_str(value) -> Optional[str]:
    if isinstance(value, list) and value:
        value = value[0]
    if value is None:
        return None
    s = str(value).strip()
    return s or None

def post_batch(base_url: str, docs: List[Dict], timeout: int) -> bool:
    if not docs:
        return True
    url = base_url.rstrip("/") + "/documents/texts"
    payload = {
        "texts": [d["text"] for d in docs],
    }
    sources = [d.get("source_url") for d in docs if d.get("source_url")]
    if sources:
        payload["file_sources"] = sources

    try:
        resp = requests.post(url, json=payload, timeout=timeout)
        if resp.ok:
            return True
        sys.stderr.write(f"Ingest failed: HTTP {resp.status_code} {resp.text[:300]}\n")
        return False
    except Exception as exc:
        sys.stderr.write(f"Ingest error: {exc}\n")
        return False

def main() -> None:
    parser = argparse.ArgumentParser(
        description="Send crawled documents to LightRAG via /documents/texts"
    )
    parser.add_argument("--root", required=True, help="Root directory of crawled data")
    parser.add_argument("--base-url", default="http://localhost:8006", help="LightRAG base URL (default: http://localhost:8006)")
    parser.add_argument("--batch", type=int, default=8, help="Number of docs per POST (default: 8)")
    parser.add_argument("--timeout", type=int, default=180, help="Request timeout in seconds (default: 180)")
    args = parser.parse_args()
    root = Path(args.root).expanduser().resolve()
    if not root.exists() or not root.is_dir():
        print(f"Root not found or not a directory: {root}", file=sys.stderr)
        sys.exit(2)
    page_dirs = discover_page_dirs(root)
    if not page_dirs:
        print("No pages found under root.")
        return
    docs: List[Dict] = []
    sent = 0
    print(f"Scanning: {root}")
    for page_dir in page_dirs:
        meta, text = load_materials(page_dir)
        if not text:
            continue

        source_url = first_str(meta.get("url") or meta.get("page_url"))
        docs.append({
            "text": text,
            "source_url": source_url or str(page_dir.relative_to(root)),
        })

        if len(docs) >= args.batch:
            ok = post_batch(args.base_url, docs, timeout=args.timeout)
            if not ok:
                print("Batch failed; continuing…", file=sys.stderr)
            else:
                sent += len(docs)
                print(f"Sent {sent} docs…")
            docs = []

    if docs:
        ok = post_batch(args.base_url, docs, timeout=args.timeout)
        if ok:
            sent += len(docs)
        print(f"Sent {sent} docs in total.")

if __name__ == "__main__":
    main()
