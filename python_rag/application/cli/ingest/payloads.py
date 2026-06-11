"""Build bridge documents for the RAG ingest API."""
from __future__ import annotations

from pathlib import Path
from typing import Any, Dict, List, Optional

from application.cli.ingest.metadata import first_str


def build_payload(
    *,
    meta: dict[str, Any],
    title: str,
    page_url: Optional[str],
    source_url: Optional[str],
    rel_path: str,
    date: Optional[str],
    meta_img: Optional[str],
    meta_img_list: list[str],
    images_list: list[str],
    pdfs_list: list[str],
    tags: list[str],
    source_format: str,
    md_path: Optional[Path],
    ingested_at: str,
) -> dict[str, Any]:
    return {
        "title": title,
        "page_url": page_url,
        "url_hash": first_str(meta.get("url_hash")),
        "canonical_url": first_str(meta.get("canonical_url")),
        "meta_img_url": meta_img_list,
        "images": images_list,
        "lang": first_str(meta.get("lang")),
        "published_at": first_str(meta.get("published_at")),
        "updated_at": first_str(meta.get("updated_at") or meta.get("updatedAt")),
        "http_status": meta.get("http_status"),
        "content_length": meta.get("content_length"),
        "fetch_time": first_str(meta.get("fetch_time") or meta.get("fetchTime")),
        "content_hash": first_str(meta.get("content_hash")),
        "pdfs": pdfs_list,
        "source_url": source_url or page_url or rel_path,
        "date": date,
        "meta_img_url_text": meta_img,
        "tags": tags or None,
        "source_format": source_format,
        "file_path": str(md_path) if md_path else None,
        "ingested_at": ingested_at,
    }


def build_bridge_doc(*, doc_id: str, text: str, payload: dict[str, Any]) -> dict[str, Any]:
    return {
        "id": doc_id,
        "text": text,
        "payload": payload,
    }
