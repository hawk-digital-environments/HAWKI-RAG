"""Document materialization helpers for retry ingest."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from application.cli.commands.materials import load_page_materials
from application.cli.commands.metadata import (
    first_str,
    make_doc_id,
    resolve_date,
    resolve_tags,
    title_from_markdown,
    to_array_list,
)
from application.cli.commands.payloads import build_bridge_doc
from application.cli.commands.url_maps import resolve_url_for_path


@dataclass(slots=True)
class RetryQueuedDocument:
    """Materialized retry document plus its normalized lookup id."""

    doc: dict[str, Any]
    doc_id: str


def queue_retry_doc(
    *,
    directory: Path,
    root: Path,
    page_url_map: dict[Path, str],
    source_url_map: dict[Path, str],
) -> RetryQueuedDocument | None:
    """Load a crawled page directory and rebuild the bridge document."""

    meta, md_path, json_path, text, source_fmt = load_page_materials(directory)
    if not isinstance(text, str) or not text.strip():
        return None

    rel_dir = str(directory.relative_to(root))
    title = first_str(meta.get("title")) or (title_from_markdown(text) or "Untitled")
    dir_resolved = directory.resolve(strict=False)
    page_url = first_str(meta.get("url") or meta.get("page_url")) or resolve_url_for_path(
        mapping=page_url_map,
        path=dir_resolved,
        root=root,
    )
    source_path = md_path or json_path or directory
    date = resolve_date(meta, source_path)
    meta_img = first_str(meta.get("metaImageUrl") or meta.get("meta_img_url"))
    updated_at = first_str(meta.get("updated_at") or meta.get("updatedAt"))
    fetch_time = first_str(meta.get("fetch_time") or meta.get("fetchTime"))
    meta_img_list = to_array_list(meta.get("meta_img_url") or meta.get("metaImageUrl"))
    images_list = to_array_list(meta.get("images"))
    pdfs_list = to_array_list(meta.get("pdfs"))
    tags = resolve_tags(meta, text)
    source_url = first_str(meta.get("source_url")) or resolve_url_for_path(
        mapping=source_url_map,
        path=dir_resolved,
        root=root,
    )
    if not source_url and page_url:
        source_url = page_url

    doc_id = make_doc_id(source_url if source_url and source_url != page_url else page_url, rel_dir)
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
        "source_url": source_url or page_url or rel_dir,
        "date": date,
        "meta_img_url_text": meta_img,
        "tags": tags or None,
        "source_format": source_fmt,
        "ingested_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
    }
    return RetryQueuedDocument(
        doc=build_bridge_doc(doc_id=doc_id, text=text, payload=payload),
        doc_id=doc_id,
    )


__all__ = ["RetryQueuedDocument", "queue_retry_doc"]
