#!/usr/bin/env python3
"""
CLI to scan a local crawled-data folder and POST documents in batches to a LightRAG server.
- Sends to LightRAG /ingest endpoint (default http://localhost:8009/ingest).

Notes:
  - This script looks for *.md, *.txt and *.json page metadata. If a folder has JSON (with fields like
    title/url/date/meta_img_url/tags) and a text file, both are used.
"""
import argparse
import logging
import hashlib
import json
import math
import os
import re
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Set, Tuple
try:
    import requests
except ImportError:
    print("This script requires 'requests'. Install with: pip install requests", file=sys.stderr)
    sys.exit(1)

logger = logging.getLogger(__name__)

############################################# READ .TXT / .MD / .JSON FILE ####################################
def read_text_file(p: Path) -> str:
    try:
        return p.read_text(encoding="utf-8", errors="ignore")
    except Exception:
        try:
            return p.read_text(errors="ignore")
        except Exception:
            return ""


def _looks_like_path_list(text: str) -> bool:
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    if len(lines) < 3:
        return False
    pathish = 0
    for line in lines:
        if line.startswith(("/", "\\\\")) or re.match(r"^[A-Za-z]:\\\\", line):
            pathish += 1
            continue
        lowered = line.lower()
        if "/app/shared/" in lowered or "/var/www/" in lowered:
            pathish += 1
            continue
        if lowered.endswith((".pdf", ".doc", ".docx", ".ppt", ".pptx")):
            pathish += 1
            continue
    return (pathish / max(len(lines), 1)) >= 0.6


def _pick_json_meta(dir_path: Path) -> Tuple[Optional[Path], Dict[str, Any]]:
    json_files = [f for f in dir_path.iterdir() if f.is_file() and f.suffix.lower() == ".json"]
    json_files = [f for f in json_files if f.name != "conversion_meta.json"]
    if not json_files:
        return None, {}

    preferred_names = ("page.json", "metadata.json", "meta.json", "page_meta.json")
    for name in preferred_names:
        for f in json_files:
            if f.name.lower() == name:
                data = _read_json_file(f)
                return f, data if isinstance(data, dict) else {}

    best_file = None
    best_data: Dict[str, Any] = {}
    for f in json_files:
        data = _read_json_file(f)
        if not isinstance(data, dict):
            continue
        if any(key in data for key in ("url", "page_url", "title", "content", "text")):
            return f, data
        if best_file is None:
            best_file = f
            best_data = data
    return best_file, best_data


def _pick_text_file(dir_path: Path) -> Tuple[Optional[Path], str]:
    # Only ingest markdown files; ignore .txt to avoid path-list artifacts.
    candidates = [f for f in dir_path.iterdir() if f.is_file() and f.suffix.lower() == ".md"]
    if candidates:
        preferred = ("content.md", "converted.md")
        candidates_by_name = {f.name.lower(): f for f in candidates}
        for name in preferred:
            if name in candidates_by_name:
                fmt = "markdown" if name == "content.md" else "converted_markdown"
                return candidates_by_name[name], fmt
        candidates.sort(key=lambda p: p.name.lower())
        return candidates[0], "markdown"

    # Look for converted_markdown.md under files/ subfolder tree.
    files_dir = dir_path / "files"
    if files_dir.is_dir():
        for path in files_dir.rglob("converted_markdown.md"):
            if path.is_file():
                return path, "converted_markdown"
    return None, ""


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
    json_path, meta = _pick_json_meta(dir_path)

    # find text (md/txt), preferring content.md / converted.md
    md_path, source_format = _pick_text_file(dir_path)

    if md_path is not None:
        candidate = read_text_file(md_path).strip()
        if candidate and not _looks_like_path_list(candidate):
            text = candidate
            logger.debug("ingest:pick_text path=%s format=%s", md_path, source_format)
        else:
            # try other candidates if the chosen text looks like a file list
            for f in dir_path.iterdir():
                if f.is_file() and f.suffix.lower() == ".md" and f != md_path:
                    candidate = read_text_file(f).strip()
                    if candidate and not _looks_like_path_list(candidate):
                        md_path = f
                        source_format = "markdown" if f.suffix.lower() == ".md" else "txt"
                        text = candidate
                        logger.debug("ingest:pick_text fallback=%s format=%s", md_path, source_format)
                        break
    if not text and meta:
        c = meta.get("content") or meta.get("text") or ""
        text = str(c).strip()
        source_format = "txt"

    return meta or {}, md_path, json_path, text, source_format


def normalize_path(path_like: Any) -> Optional[Path]:
    if path_like is None:
        return None
    try:
        path = Path(str(path_like)).expanduser()
        return path.resolve(strict=False)
    except Exception:
        return None


def _read_json_file(path: Path) -> Dict[str, Any]:
    try:
        return json.loads(path.read_text(encoding="utf-8", errors="ignore"))
    except Exception:
        try:
            return json.loads(path.read_text(errors="ignore"))
        except Exception:
            return {}


def build_url_maps(root: Path) -> Tuple[Dict[Path, str], Dict[Path, str]]:
    """
    Build lookup maps so directories can resolve their page/source URLs.
    Returns: (page_url_map, source_url_map)
    """
    page_url_map: Dict[Path, str] = {}
    source_url_map: Dict[Path, str] = {}
    pdf_lookup: Dict[Path, Dict[str, str]] = {}
    root_resolved = root.resolve(strict=False)

    # Pass 1: collect page URLs and PDF mappings from primary JSON metadata.
    for dirpath, _, filenames in os.walk(root):
        dir_path = Path(dirpath)
        dir_resolved = dir_path.resolve(strict=False)
        json_files = [fn for fn in filenames if fn.lower().endswith(".json")]
        if not json_files:
            continue
        for fname in json_files:
            if fname == "conversion_meta.json":
                continue
            data = _read_json_file(dir_path / fname)
            if not isinstance(data, dict):
                continue
            page_url = first_str(data.get("url") or data.get("page_url"))
            if page_url:
                page_url_map.setdefault(dir_resolved, page_url)
            pdfs = data.get("pdfs")
            if isinstance(pdfs, list):
                for pdf_entry in pdfs:
                    if not isinstance(pdf_entry, dict):
                        continue
                    local_path = normalize_path(pdf_entry.get("local_path"))
                    pdf_url = first_str(pdf_entry.get("url"))
                    if not local_path or not pdf_url:
                        continue
                    pdf_lookup[local_path] = {
                        "page_url": page_url,
                        "source_url": pdf_url,
                    }

    # Pass 2: link converted PDF output directories back to their source/page URLs.
    for dirpath, _, filenames in os.walk(root):
        if "conversion_meta.json" not in filenames:
            continue
        dir_path = Path(dirpath)
        conv_meta = _read_json_file(dir_path / "conversion_meta.json")
        if not isinstance(conv_meta, dict):
            continue
        source_pdf = normalize_path(conv_meta.get("source_pdf"))
        if not source_pdf:
            continue
        info = pdf_lookup.get(source_pdf)
        if not info:
            continue
        target_dirs: Set[Path] = set()
        dir_resolved = dir_path.resolve(strict=False)
        target_dirs.add(dir_resolved)
        output_dir = normalize_path(conv_meta.get("output_dir"))
        if output_dir:
            target_dirs.add(output_dir)
        for base in list(target_dirs):
            output_path = normalize_path(base / "output")
            if output_path:
                target_dirs.add(output_path)
        for tdir in target_dirs:
            if not tdir:
                continue
            if info.get("page_url"):
                page_url_map.setdefault(tdir, info["page_url"])
            if info.get("source_url"):
                source_url_map.setdefault(tdir, info["source_url"])

    # Default source_url to page_url where specific overrides do not exist.
    for dir_path, page_url in page_url_map.items():
        source_url_map.setdefault(dir_path, page_url)

    # Ensure root always resolves within the maps to avoid lookups failing at top-level.
    if root_resolved in page_url_map and root_resolved not in source_url_map:
        source_url_map[root_resolved] = page_url_map[root_resolved]

    return page_url_map, source_url_map


def resolve_url_for_path(mapping: Dict[Path, str], path: Path, root: Path) -> Optional[str]:
    """
    Walk up from path towards root to locate the closest mapped URL.
    """
    try:
        current = path.resolve(strict=False)
        root_resolved = root.resolve(strict=False)
    except Exception:
        return None

    while True:
        if current in mapping:
            return mapping[current]
        if current == root_resolved:
            break
        parent = current.parent
        if parent == current:
            break
        try:
            if not current.is_relative_to(root_resolved):
                break
        except AttributeError:
            # Python < 3.9 fallback
            cur_str = str(current)
            root_str = str(root_resolved)
            if not cur_str.startswith(root_str.rstrip(os.sep) + os.sep):
                break
        current = parent

    return None
########################################### PAGE DIR DISCOVERY #####################################
def discover_page_dirs(root: Path) -> List[Path]:
    """Return folders that look like a 'page' (contains json or md/txt)."""
    out: List[Path] = []
    for dp, dn, fn in os.walk(root):
        p = Path(dp)
        # Skip converted output folders to avoid duplicate ingestion.
        parts = [part.lower() for part in p.parts]
        if any(part.startswith("converted_") for part in parts):
            dn[:] = []
            continue
        if "output" in parts and any(part.startswith("converted_") for part in parts):
            dn[:] = []
            continue

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
    if not s:
        return None
    lowered = s.lower()
    if lowered in {"null", "none", "n/a", "undefined"}:
        return None
    return s

def resolve_date(meta: Dict[str, Any], fallback_path: Optional[Path]) -> Optional[str]:
    date = first_str(meta.get("date"))
    if not date:
        date = first_str(meta.get("published_at") or meta.get("updated_at") or meta.get("modified_at"))
    if not date:
        try:
            if fallback_path and fallback_path.exists():
                date = datetime.fromtimestamp(fallback_path.stat().st_mtime, tz=timezone.utc).isoformat()
        except Exception:
            date = None
    return date

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

    stop_path = Path(__file__).resolve().parents[1] / "config" / "german_stopwords_plain.txt"
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


def utc_now_iso() -> str:
    """Return an ISO8601 UTC timestamp with trailing Z."""
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")

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


def extract_pdf_links(text: str) -> List[str]:
    if not text:
        return []
    pattern = re.compile(r"https?://[^\s)>\"]+?\.pdf", re.IGNORECASE)
    links = pattern.findall(text)
    seen = set()
    out: List[str] = []
    for link in links:
        clean = link.rstrip(").,;\"'")
        if clean not in seen:
            seen.add(clean)
            out.append(clean)
    return out
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
    batch_size = args.batch
    summary: Dict[str, Any] = {
        "timestamp": utc_now_iso(),
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


def _safe_state_filename(key: str) -> str:
    digest = hashlib.sha1(key.encode("utf-8", errors="ignore")).hexdigest()
    return f"{digest}.json"


def load_resume_state(path: Path) -> Set[str]:
    if not path.exists():
        return set()
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return set()
    doc_ids = data.get("doc_ids", [])
    return {str(doc_id) for doc_id in doc_ids if isinstance(doc_id, (str, int))}


def save_resume_state(path: Path, doc_ids: Set[str], metadata: Dict[str, Any]) -> None:
    try:
        payload = {
            "doc_ids": sorted(doc_ids),
            "updated_at": utc_now_iso(),
        }
        payload.update(metadata)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    except Exception as exc:
        print(f"Warning: failed to persist resume state to {path}: {exc}", file=sys.stderr)

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

def should_split_batch(err: Optional[str]) -> bool:
    if not err:
        return False
    lowered = err.lower()
    retry_markers = [
        "timed out",
        "timeout",
        "read timed out",
        "502",
        "503",
        "504",
        "bad gateway",
        "gateway",
        "service unavailable",
    ]
    return any(marker in lowered for marker in retry_markers)

def main():
    ap = argparse.ArgumentParser(description="Ingest local crawled-data into LightRAG via HTTP.")
    def _int_env(name: str, default: int) -> int:
        raw = os.environ.get(name)
        if raw is None or str(raw).strip() == "":
            return default
        try:
            return int(raw)
        except ValueError:
            return default
    ap.add_argument("--root", required=True, help="Path to local crawled-data root")
    ap.add_argument("--base-url", default="http://localhost:8009", help="LightRAG base URL (default: http://localhost:8009)")
    ap.add_argument("--provider", default="ollama", help="Embedding/LLM provider name (beware that gwdg is only for chat completio)")
    ap.add_argument("--embedding-model", default=None, help="Embedding model override (e.g. bge-m3, qwen3-embedding)")
    ap.add_argument("--graph", action="store_true", help="Enable KG extraction during ingest")
    ap.add_argument("--graph-only", action="store_true", help="Skip Qdrant/embeddings and only write Neo4j triplets")
    ap.add_argument("--graph-engine", default="raganything", help="Graph engine")
    ap.add_argument("--collection", default=None, help="Qdrant collection override")
    ap.add_argument("--distance", default="Cosine", help="Qdrant distance (Cosine|Dot|Euclid)")
    ap.add_argument("--chunk-chars", type=int, default=_int_env("CHUNK_SIZE", 1200), help="Chunk target size for LightRAG")
    ap.add_argument("--chunk-overlap", type=int, default=_int_env("CHUNK_OVERLAP_SIZE", 100), help="Chunk overlap for LightRAG")
    ap.add_argument("--batch", type=int, default=64, help="POST batch size (docs per request)")
    ap.add_argument("--timeout", type=int, default=1800, help="HTTP request timeout in seconds (default: 1800)")
    ap.add_argument("--dry", action="store_true", help="Perform a dry run to preview Qdrant/Neo4j impact without embeddings")
    ap.add_argument("--summary-file", default=None, help="Optional path to save the ingest summary JSON")
    ap.add_argument("--dry-include-graph", action="store_true", help="When used with --dry, also estimate Neo4j entities/relationships")
    ap.add_argument("--estimate-only", action="store_true", help="Estimate chunk/point counts locally without contacting the server")
    ap.add_argument("--resume-state-dir", default="storage/app/private/ingest-state", help="Directory where resume markers are stored")
    ap.add_argument("--resume", action="store_true", help="Resume by skipping already ingested docs (non-interactive)")
    ap.add_argument("--start", action="store_true", help="Start fresh and ignore previous state (non-interactive)")
    args = ap.parse_args()
    if args.resume and args.start:
        print("Choose only one of --resume or --start.", file=sys.stderr)
        sys.exit(2)

    root = Path(args.root).expanduser().resolve()
    if not root.exists() or not root.is_dir():
        print(f"Root not found or not a directory: {root}", file=sys.stderr)
        sys.exit(2)
    if not args.collection:
        args.collection = root.name

    resume_doc_ids: Set[str] = set()
    resume_state_path: Optional[Path] = None
    resume_metadata: Dict[str, Any] = {}
    resume_mode = False
    state_dir = Path(args.resume_state_dir).expanduser().resolve()
    resume_key_parts = [
        args.collection or "default",
        str(root),
        args.base_url.rstrip("/"),
    ]
    # Graph-only runs should not reuse the embedding/Qdrant resume state.
    if args.graph_only:
        resume_key_parts.append("graph_only")
    resume_key = "::".join(resume_key_parts)
    if not args.dry and not args.estimate_only:
        resume_state_path = state_dir / _safe_state_filename(resume_key)
        existing_ids = load_resume_state(resume_state_path)
        resume_metadata = {
            "collection": args.collection,
            "root": str(root),
            "base_url": args.base_url,
            "graph_only": bool(args.graph_only),
            "graph": bool(args.graph),
        }
        if existing_ids:
            print(
                f"Found previous ingest state for '{resume_key_parts[0]}' with {len(existing_ids)} documents."
            )
            if args.resume:
                choice = "resume"
            elif args.start:
                choice = "start"
            else:
                while True:
                    choice = input("Type 'resume' to skip already-ingested docs or 'start' to process everything again [resume/start]: ").strip().lower()
                    if choice in {"", "resume", "start"}:
                        break
                    print("Please enter 'resume' or 'start'.")
            if choice in {"", "resume"}:
                resume_mode = True
                resume_doc_ids = existing_ids
                print(f"Resuming ingest; skipping {len(resume_doc_ids)} documents already processed.")
            else:
                resume_mode = False
                resume_doc_ids = set()
                try:
                    resume_state_path.unlink(missing_ok=True)
                except Exception as exc:
                    print(f"Warning: failed to remove existing resume state: {exc}", file=sys.stderr)
                print("Starting fresh; previous state will be replaced.")
        else:
            print(f"No previous ingest state found for '{resume_key_parts[0]}'. Starting fresh.")
    else:
        resume_state_path = None

    page_url_map, source_url_map = build_url_maps(root)

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
        "batch_size": int(args.batch),
    }
    if args.graph_only:
        options["graph_only"] = True
    if args.embedding_model:
        options["embedding_model"] = args.embedding_model
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
    skipped_existing = 0
    processed_doc_ids: Set[str] = set(resume_doc_ids)

    logger.info("ingest:scan root=%s", root)
    print(f"Scanning: {root}")
    if args.dry:
        print("Running in dry-run mode; embeddings and database writes are skipped.")
    total_dirs = len(page_dirs)
    logger.info("ingest:folders total=%s", total_dirs)
    print(f"Discovered {total_dirs} page folders.")
    min_split_batch = int(os.environ.get("INGEST_MIN_BATCH", "4"))
    max_split_depth = int(os.environ.get("INGEST_MAX_SPLITS", "4"))

    def send_batch(docs_batch: List[Dict], depth: int = 0) -> bool:
        nonlocal batch_index, sent, last_response, processed_doc_ids
        if not docs_batch:
            return True
        batch_index += 1
        doc_ids_batch = [doc.get("id") for doc in docs_batch]
        ok, data, err = post_batch(args.base_url, docs_batch, options, timeout=args.timeout)
        if ok:
            logger.info("ingest:batch sent=%s docs=%s", batch_index, len(docs_batch))
            sent += len(docs_batch)
            if args.dry:
                print(f"Planned {sent}/{total} docs… (batch {batch_index}) [dry-run]")
            else:
                print(f"Sent {sent}/{total} docs… (batch {batch_index})")
            if data:
                last_response = data
            if not args.dry and resume_state_path is not None:
                processed_doc_ids.update(str(doc.get("id")) for doc in docs_batch if doc.get("id"))
                save_resume_state(resume_state_path, processed_doc_ids, resume_metadata)
            return True
        if should_split_batch(err) and len(docs_batch) > max(1, min_split_batch) and depth < max_split_depth:
            mid = max(1, len(docs_batch) // 2)
            left = docs_batch[:mid]
            right = docs_batch[mid:]
            print(
                f"Batch {batch_index} failed; splitting {len(docs_batch)} into {len(left)} + {len(right)} due to timeout/5xx.",
                file=sys.stderr,
            )
            left_ok = send_batch(left, depth + 1)
            right_ok = send_batch(right, depth + 1)
            return left_ok and right_ok
        print(f"Batch {batch_index} failed; docs={doc_ids_batch} ({err or 'see log'})", file=sys.stderr)
        return False

    for idx, d in enumerate(page_dirs, start=1):
        rel_dir = str(d.relative_to(root))
        print(f"Folder {idx}/{total_dirs}: {rel_dir}")
        meta, md_path, json_path, text, source_fmt = load_page_materials(d)
        if not isinstance(text, str) or text.strip() == "":
            continue

        title = first_str(meta.get("title")) or (title_from_markdown(text) or "Untitled")
        dir_resolved = d.resolve(strict=False)
        page_url = first_str(meta.get("url") or meta.get("page_url")) or resolve_url_for_path(page_url_map, dir_resolved, root)
        source_path = md_path or json_path or d
        date = resolve_date(meta, source_path)
        meta_img = first_str(meta.get("metaImageUrl") or meta.get("meta_img_url"))
        updated_at = first_str(meta.get("updated_at") or meta.get("updatedAt"))
        fetch_time = first_str(meta.get("fetch_time") or meta.get("fetchTime"))
        title_list = to_array_list(meta.get("title")) or ([title] if title else [])
        page_url_list = to_array_list(meta.get("page_url") or meta.get("url")) or ([page_url] if page_url else [])
        meta_img_list = to_array_list(meta.get("meta_img_url") or meta.get("metaImageUrl"))
        images_list = to_array_list(meta.get("images"))
        pdfs_list = to_array_list(meta.get("pdfs"))
        if not pdfs_list:
            pdfs_list = extract_pdf_links(text)
        tags = resolve_tags(meta, text)
        rel = str(d.relative_to(root))
        source_url = first_str(meta.get("source_url")) or resolve_url_for_path(source_url_map, dir_resolved, root)
        if not source_url and page_url:
            source_url = page_url
        doc_id = make_doc_id(source_url if source_url and source_url != page_url else page_url, rel)

        if resume_mode and doc_id in resume_doc_ids:
            skipped_existing += 1
            continue

        payload = {
            "title": title,
            "page_url": page_url,
            "url_hash": first_str(meta.get("url_hash")),
            "canonical_url": first_str(meta.get("canonical_url")),
            "meta_img_url": meta_img_list,
            "images": images_list,
            "lang": first_str(meta.get("lang")),
            "published_at": first_str(meta.get("published_at")),
            "updated_at": updated_at,
            "http_status": meta.get("http_status"),
            "content_length": meta.get("content_length"),
            "fetch_time": fetch_time,
            "content_hash": first_str(meta.get("content_hash")),
            "pdfs": pdfs_list,
            "source_url": source_url or page_url or rel,
            "date": date,
            "meta_img_url_text": meta_img,
            "tags": tags or None,
            "source_format": source_fmt,
            "ingested_at": utc_now_iso(),
        }

        docs.append({
            "id": doc_id,
            "text": text,
            "payload": payload,
        })
        total += 1

        if len(docs) >= args.batch:
            send_batch(docs, depth=0)
            docs = []

    if docs:
        ok = send_batch(docs, depth=0)
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

    if resume_state_path is not None:
        if not args.dry:
            print(f"Resume state stored at {resume_state_path}")
        if resume_mode and skipped_existing:
            print(f"Skipped {skipped_existing} documents already ingested earlier.")

if __name__ == "__main__":
    main()
