#!/usr/bin/env python3
"""
CLI to scan a local crawled-data folder and POST documents in batches to a LightRAG server.
- Sends to LightRAG /ingest endpoint (default http://localhost:8009/ingest).

Notes:
  - This script looks for *.md, *.txt and *.json page metadata. If a folder has JSON (with fields like
    title/url/date/meta_img_url/tags) and a text file, both are used.
"""
import argparse
import hashlib
import json
import math
import os
import re
import sys
from collections import Counter
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Tuple
try:
    import requests
except ImportError:
    print("This script requires 'requests'. Install with: pip install requests", file=sys.stderr)
    sys.exit(1)

############################################# READ .TXT / .MD / .JSON FILE ####################################
def read_text_file(p: Path) -> str:
    try:
        return p.read_text(encoding="utf-8", errors="ignore")
    except Exception:
        try:
            return p.read_text(errors="ignore")
        except Exception:
            return ""


def load_page_materials(dir_path: Path) -> Tuple[Dict, Optional[Path], Optional[Path], str, str]:
    """
    Reads optional page.json (or any *.json) and first *.md/*.txt content in a folder.
    Returns: (meta, md_path, json_path, text, source_format)
    """
    meta: Dict = {}
    json_path: Optional[Path] = None
    md_path: Optional[Path] = None
    text = ""
    source_format = "txt"

    # find JSON
    for f in dir_path.iterdir():
        if f.is_file() and f.suffix.lower() == ".json":
            json_path = f
            break
    if json_path is not None:
        try:
            meta = json.loads(json_path.read_text(encoding="utf-8", errors="ignore"))
        except Exception:
            meta = {}

    # find text (md/txt)
    for f in dir_path.iterdir():
        if f.is_file() and f.suffix.lower() in {".md", ".txt"}:
            md_path = f
            source_format = "markdown" if f.suffix.lower() == ".md" else "txt"
            break

    if md_path is not None:
        text = read_text_file(md_path).strip()
    elif meta:
        c = meta.get("content") or meta.get("text") or ""
        text = str(c).strip()
        source_format = "txt"

    return meta or {}, md_path, json_path, text, source_format
########################################### PAGE DIR DISCOVERY #####################################
def discover_page_dirs(root: Path) -> List[Path]:
    """Return folders that look like a 'page' (contains json or md/txt)."""
    out: List[Path] = []
    for dp, dn, fn in os.walk(root):
        p = Path(dp)
        files = {Path(dp, f).suffix.lower() for f in fn}
        if any(s in files for s in (".json", ".md", ".txt")):
            out.append(p)
    return out

def first_str(v) -> Optional[str]:
    if isinstance(v, list) and v:
        v = v[0]
    if v is None:
        return None
    s = str(v).strip()
    return s or None

def to_array_list(v) -> List[str]:
    if isinstance(v, str):
        return [v]
    if not isinstance(v, list):
        return []
    out: List[str] = []
    for x in v:
        s = first_str(x)
        if s:
            out.append(s)
    return out
########################################### PREPROCESSING LOGICS #####################################

def load_stopwords() -> set[str]:

    stop_path = Path(__file__).resolve().parent.parent / "python-rag" / "german_stopwords_plain.txt"
    try:
        content = stop_path.read_text(encoding="utf-8")
    except FileNotFoundError:
        return print("stopword file not found")
    words = {
        line.strip().lower()
        for line in content.splitlines()
        if line.strip() and not line.strip().startswith("#")
    }
    return words 
STOPWORDS = load_stopwords()

def _flatten_keywords(raw) -> Iterable[str]:
    if raw is None:
        return []
    if isinstance(raw, (list, tuple, set)):
        items: List[str] = []
        for item in raw:
            items.extend(list(_flatten_keywords(item)))
        return items
    if isinstance(raw, str):
        # remove leading bullets / numbering similar to PHP coerceKeywords
        cleaned = re.sub(r"^[^\n:]{0,200}:\s*", "", raw)
        cleaned = re.sub(r"-\s*\d+\s*[\.-]?\s*", "\n", cleaned)
        cleaned = re.sub(r"\s*\d+\s*[\.\)\:\-]\s*", "\n", cleaned)
        parts = []
        for line in re.split(r"[\r\n]+", cleaned):
            line = re.sub(r"^\s*[\-\*\•\u2022]?\s*", "", line)
            parts.extend(re.split(r"[,;]+", line))
        return [p.strip() for p in parts if p.strip()]
    return [str(raw).strip()]

def normalize_tags(candidates: Iterable[str], limit: int = 10) -> List[str]:
    out: List[str] = []
    seen = set()
    for cand in candidates:
        cand = cand.replace("-", " ")
        cand = re.sub(r"[^\w\s]", " ", cand, flags=re.UNICODE)
        cand = re.sub(r"\s+", " ", cand).strip().lower()
        if not cand or len(cand) < 2:
            continue
        if cand not in seen:
            seen.add(cand)
            out.append(cand)
        if len(out) >= limit:
            break
    return out

def fallback_keywords(text: str, limit: int = 10) -> List[str]:
    if not text:
        return []
    text = text.lower()
    # allow extended latin letters
    words = re.findall(r"[a-z\u00c0-\u024f]+", text)
    counts: Counter[str] = Counter()
    for w in words:
        if len(w) < 4 or w in STOPWORDS:
            continue
        counts[w] += 1
    out: List[str] = []
    for word, _ in counts.most_common(limit * 2):
        if word not in out:
            out.append(word)
        if len(out) >= limit:
            break
    return out

def resolve_tags(meta: Dict, text: str) -> List[str]:
    raw_sources = [
        meta.get("tags"),
        meta.get("keywords"),
        meta.get("labels"),
    ]
    tags = normalize_tags(_flatten_keywords([s for s in raw_sources if s]))
    if tags:
        return tags
    return fallback_keywords(text)
########################################### TITLES AND DOC IDS PROCESSING #####################################

def title_from_markdown(md: str) -> Optional[str]:
    for line in md.splitlines():
        t = line.strip().lstrip("# ").strip()
        if t:
            return t[:200]
    return None
def make_doc_id(page_url: Optional[str], rel_path: str) -> str:
    base = page_url or rel_path
    return hashlib.sha1(base.encode("utf-8", errors="ignore")).hexdigest()

########################################### BATCH PROCESSING #####################################

def split_text_local(txt: str, target: int, overlap: int) -> List[str]:
    txt = (txt or "").strip()
    if not txt:
        return []
    if len(txt) <= target:
        return [txt]
    out: List[str] = []
    start = 0
    length = len(txt)
    while start < length:
        end = min(length, start + target)
        slice_ = txt[start:end]
        cut = slice_.rfind("\n\n")
        if cut != -1 and cut > int(target * 0.6):
            end = start + cut
        chunk = txt[start:end].strip()
        if chunk:
            out.append(chunk)
        if end >= length:
            break
        start = max(0, end - overlap)
    return out

def batch(iterable, size: int):
    buf = []
    for item in iterable:
        buf.append(item)
        if len(buf) >= size:
            yield buf
            buf = []
    if buf:
        yield buf


def run_local_estimate(
    *,
    page_dirs: List[Path],
    root: Path,
    chunk_chars: int,
    chunk_overlap: int,
    collection: Optional[str],
) -> Dict[str, Any]:
    doc_stats: Dict[str, Any] = {
        "total_docs": len(page_dirs),
        "processed_docs": 0,
        "skipped_docs": 0,
        "by_format": {},
        "doc_ids": [],
    }
    total_chunks = 0

    for d in page_dirs:
        meta, _, _, text, source_fmt = load_page_materials(d)
        if not isinstance(text, str) or text.strip() == "":
            doc_stats["skipped_docs"] += 1
            continue

        page_url = first_str(meta.get("url") or meta.get("page_url"))
        rel = str(d.relative_to(root))
        doc_id = make_doc_id(page_url, rel)
        chunks = split_text_local(text, chunk_chars, chunk_overlap) or [text]
        chunk_count = 0
        for ch in chunks:
            if isinstance(ch, str) and ch.strip():
                chunk_count += 1

        if chunk_count == 0:
            doc_stats["skipped_docs"] += 1
            continue

        doc_stats["processed_docs"] += 1
        doc_stats["doc_ids"].append(doc_id)
        fmt_key = source_fmt or "unknown"
        by_fmt = doc_stats["by_format"]
        by_fmt[fmt_key] = by_fmt.get(fmt_key, 0) + 1
        chunks_map = doc_stats.setdefault("chunks_per_doc", {})
        chunks_map[doc_id] = chunk_count
        total_chunks += chunk_count

    doc_stats["total_chunks"] = total_chunks
    batch_size = 64
    summary: Dict[str, Any] = {
        "timestamp": datetime.utcnow().isoformat() + "Z",
        "estimate_only": True,
        "planned_points": total_chunks,
        "documents": doc_stats,
        "qdrant_preview": {
            "collection": collection or "(server default)",
            "batch_size": batch_size,
            "planned_batches": math.ceil(total_chunks / batch_size) if total_chunks else 0,
            "planned_points": total_chunks,
        },
        "graph_preview_skipped": "Local estimate does not analyze Neo4j impact.",
    }
    return summary

def post_batch(base_url: str, docs: List[Dict], options: Dict, timeout: int) -> tuple[bool, Optional[Dict], Optional[str]]:
    url = base_url.rstrip("/") + "/ingest"
    body = {"docs": docs}
    body.update(options)
    try:
        resp = requests.post(url, json=body, timeout=timeout)
        if resp.ok:
            try:
                data = resp.json()
            except ValueError:
                data = None
            return True, data, None
        err = f"HTTP {resp.status_code} {resp.text[:300]}"
        sys.stderr.write(f"Ingest failed: {err}\n")
        return False, None, err
    except Exception as e:
        err = f"Exception: {e}"
        sys.stderr.write(f"Ingest error: {err}\n")
        return False, None, err

def main():
    ap = argparse.ArgumentParser(description="Ingest local crawled-data into LightRAG via HTTP.")
    ap.add_argument("--root", required=True, help="Path to local crawled-data root")
    ap.add_argument("--base-url", default="http://localhost:8009", help="LightRAG base URL (default: http://localhost:8009)")
    ap.add_argument("--provider", default="ollama", help="Embedding/LLM provider name (beware that gwdg is only for chat completio)")
    ap.add_argument("--graph", action="store_true", help="Enable KG extraction during ingest")
    ap.add_argument("--graph-engine", default="lightrag", help="Graph engine")
    ap.add_argument("--collection", default=None, help="Qdrant collection override")
    ap.add_argument("--distance", default="Cosine", help="Qdrant distance (Cosine|Dot|Euclid)")
    ap.add_argument("--chunk-chars", type=int, default=3200, help="Chunk target size for LightRAG")
    ap.add_argument("--chunk-overlap", type=int, default=100, help="Chunk overlap for LightRAG")
    ap.add_argument("--batch", type=int, default=64, help="POST batch size (docs per request)")
    ap.add_argument("--timeout", type=int, default=600, help="HTTP request timeout in seconds (default: 600)")
    ap.add_argument("--dry", action="store_true", help="Perform a dry run to preview Qdrant/Neo4j impact without embeddings")
    ap.add_argument("--summary-file", default=None, help="Optional path to save the ingest summary JSON")
    ap.add_argument("--dry-include-graph", action="store_true", help="When used with --dry, also estimate Neo4j entities/relationships")
    ap.add_argument("--estimate-only", action="store_true", help="Estimate chunk/point counts locally without contacting the server")
    args = ap.parse_args()

    root = Path(args.root).expanduser().resolve()
    if not root.exists() or not root.is_dir():
        print(f"Root not found or not a directory: {root}", file=sys.stderr)
        sys.exit(2)

    page_dirs = discover_page_dirs(root)
    if not page_dirs:
        print("No pages found under root.")
        return

    if args.estimate_only:
        print(f"Scanning: {root}")
        print("Running local estimate; server is not contacted.")
        summary = run_local_estimate(
            page_dirs=page_dirs,
            root=root,
            chunk_chars=args.chunk_chars,
            chunk_overlap=args.chunk_overlap,
            collection=args.collection,
        )
        preview = json.dumps(summary, indent=2, ensure_ascii=False)
        print(preview)
        if args.summary_file:
            out_path = Path(args.summary_file).expanduser().resolve()
            try:
                out_path.parent.mkdir(parents=True, exist_ok=True)
                out_path.write_text(preview + "\n", encoding="utf-8")
                print(f"Saved estimate summary to {out_path}")
            except Exception as exc:
                print(f"Failed to write summary to {out_path}: {exc}", file=sys.stderr)
        return

    options = {
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

    docs: List[Dict] = []
    total = 0
    sent = 0
    batch_index = 0
    last_response: Optional[Dict] = None

    print(f"Scanning: {root}")
    if args.dry:
        print("Running in dry-run mode; embeddings and database writes are skipped.")
    for d in page_dirs:
        meta, md_path, json_path, text, source_fmt = load_page_materials(d)
        if not isinstance(text, str) or text.strip() == "":
            continue

        title = first_str(meta.get("title")) or (title_from_markdown(text) or "Untitled")
        page_url = first_str(meta.get("url") or meta.get("page_url"))
        date = first_str(meta.get("date"))
        meta_img = first_str(meta.get("metaImageUrl") or meta.get("meta_img_url"))
        tags = resolve_tags(meta, text)
        rel = str(d.relative_to(root))
        doc_id = make_doc_id(page_url, rel)

        payload = {
            "title": title,
            "page_url": page_url or rel,
            "source_url": page_url or rel,
            "date": date,
            "meta_img_url": meta_img,
            "tags": tags or None,
            "source_format": source_fmt,
        }

        docs.append({
            "id": doc_id,
            "text": text,
            "payload": payload,
        })
        total += 1

        if len(docs) >= args.batch:
            batch_index += 1
            doc_ids_batch = [doc.get("id") for doc in docs]
            ok, data, err = post_batch(args.base_url, docs, options, timeout=args.timeout)
            if not ok:
                print(f"Batch {batch_index} failed; docs={doc_ids_batch} ({err or 'see log'})", file=sys.stderr)
            else:
                sent += len(docs)
                if args.dry:
                    print(f"Planned {sent}/{total} docs… (batch {batch_index}) [dry-run]")
                else:
                    print(f"Sent {sent}/{total} docs… (batch {batch_index})")
                if data:
                    last_response = data
            docs = []

    if docs:
        batch_index += 1
        doc_ids_batch = [doc.get("id") for doc in docs]
        ok, data, err = post_batch(args.base_url, docs, options, timeout=args.timeout)
        if ok:
            sent += len(docs)
            if data:
                last_response = data
        else:
            print(f"Batch {batch_index} failed; docs={doc_ids_batch} ({err or 'see log'})", file=sys.stderr)
        if args.dry:
            print(f"Planned {sent}/{total} docs. Dry run complete.")
        else:
            print(f"Sent {sent}/{total} docs. Done.")

    if args.dry and last_response:
        summary = last_response.get("summary") or {}
        planned_points = summary.get("planned_points")
        if planned_points is not None:
            print(f"[dry-run] Estimated Qdrant points: {planned_points}")
        graph_preview = summary.get("graph_preview") or {}
        if graph_preview:
            planned_entities = graph_preview.get("planned_entities")
            planned_triplets = graph_preview.get("planned_triplets")
            if planned_entities is not None:
                print(f"[dry-run] Estimated Neo4j entities: {planned_entities}")
            if planned_triplets is not None:
                print(f"[dry-run] Estimated Neo4j relationships: {planned_triplets}")

    if args.summary_file and last_response:
        summary = last_response.get("summary")
        if summary:
            out_path = Path(args.summary_file).expanduser().resolve()
            try:
                out_path.parent.mkdir(parents=True, exist_ok=True)
                out_path.write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
                print(f"Saved summary to {out_path}")
            except Exception as exc:
                print(f"Failed to write summary to {out_path}: {exc}", file=sys.stderr)

if __name__ == "__main__":
    main()
