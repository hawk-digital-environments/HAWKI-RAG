"""Page-to-bridge-document materialization for crawl-ingest runner."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any

from application.cli.commands.estimation import utc_now_iso
from application.cli.commands.links import extract_pdf_links
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
from application.cli.commands.url_maps import resolve_url_for_path


@dataclass(slots=True)
class PageDocumentResult:
    """Materialized bridge document for one crawled page directory."""

    rel_dir: str
    doc_id: str | None
    doc: dict[str, Any] | None
    empty: bool


def build_page_document(
    *,
    directory: Path,
    root: Path,
    page_url_map: dict[Path, str],
    source_url_map: dict[Path, str],
) -> PageDocumentResult:
    """Load page materials and convert them to a bridge ingest document."""

    rel_dir = str(directory.relative_to(root))
    meta, md_path, json_path, text, source_fmt = load_page_materials(directory)
    if not isinstance(text, str) or text.strip() == "":
        return PageDocumentResult(rel_dir=rel_dir, doc_id=None, doc=None, empty=True)

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
    images_list = to_array_list(meta.get("images"))
    pdfs_list = to_array_list(meta.get("pdfs"))
    if not pdfs_list:
        pdfs_list = extract_pdf_links(text)
    tags = resolve_tags(meta, text)
    source_url = first_str(meta.get("source_url")) or resolve_url_for_path(
        mapping=source_url_map,
        path=dir_resolved,
        root=root,
    )
    if not source_url and page_url:
        source_url = page_url

    doc_id = make_doc_id(source_url if source_url and source_url != page_url else page_url, rel_dir)
    payload = build_payload(
        meta=meta,
        title=title,
        page_url=page_url,
        source_url=source_url,
        rel_path=rel_dir,
        date=date,
        meta_img=meta_img,
        meta_img_list=to_array_list(meta.get("meta_img_url") or meta.get("metaImageUrl")),
        images_list=images_list,
        pdfs_list=pdfs_list,
        tags=tags,
        source_format=source_fmt,
        md_path=md_path,
        ingested_at=utc_now_iso(),
    )
    return PageDocumentResult(
        rel_dir=rel_dir,
        doc_id=doc_id,
        doc=build_bridge_doc(doc_id=doc_id, text=text, payload=payload),
        empty=False,
    )


__all__ = ["PageDocumentResult", "build_page_document"]
