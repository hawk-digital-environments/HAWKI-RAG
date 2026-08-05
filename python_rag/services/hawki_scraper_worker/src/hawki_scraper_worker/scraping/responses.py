"""Normalize external crawler results without hiding incomplete crawls."""

from __future__ import annotations

from pathlib import Path
from typing import Any

from hawki_artifact_store.local import LocalArtifactStore


SCRAPER_BOOKKEEPING_FILENAMES = frozenset(
    {
        "crawler.log",
        "job_state.json",
        "summary.json",
        "urls_index.json",
    }
)


def normalize_scrape_result(
    response: dict[str, Any],
    start_payload: dict[str, Any],
    source_id: str,
    raw_dir: str,
    artifact_store: LocalArtifactStore,
) -> dict[str, Any]:
    """Preserve the legacy scrape result and incomplete-crawl checks."""

    status = normalize_status(response)
    crawler_raw_dir = shared_worker_path(
        response.get("raw_dir")
        or response.get("raw_output_path")
        or response.get("output_directory"),
        shared_root=artifact_store.shared_root,
    )
    output_dir = crawler_raw_dir or raw_dir
    crawled_file_count = crawled_output_file_count(output_dir, artifact_store)
    pages_crawled = (
        positive_int(response.get("pages_crawled"))
        or positive_int(response.get("files_found"))
        or positive_int(response.get("file_count"))
        or crawled_file_count
        or 0
    )
    page_limit = positive_int(start_payload.get("max_pages"))
    error_details = response.get("error") or response.get("error_details")
    if status == "success" and pages_crawled <= 0:
        status = "failed"
        error_details = error_details or "Scraper completed without crawled page files."
    elif status == "success" and page_limit is not None and pages_crawled < page_limit:
        status = "failed"
        error_details = error_details or (
            f"Scraper stopped at {pages_crawled}/{page_limit} pages before reaching "
            "the configured page limit."
        )

    result = {
        "source_id": source_id,
        "external_job_id": response.get("external_job_id"),
        "raw_dir": output_dir,
        "files_found": pages_crawled,
        "pages_crawled": pages_crawled,
        "raw_files_found": crawled_file_count,
        "status": status,
        "error_details": error_details,
    }
    if page_limit is not None:
        result["max_pages"] = page_limit
    return result


def shared_worker_path(
    value: object,
    *,
    shared_root: str | Path = "/shared",
) -> str | None:
    """Map known producer mount points to the shared worker mount."""

    if not isinstance(value, str) or not value.strip():
        return None
    path = value.strip()
    target_root = str(shared_root).rstrip("/") or "/"
    for prefix in ("/var/www/html/shared", "/app/shared"):
        if path == prefix:
            return target_root
        if path.startswith(prefix + "/"):
            return target_root + "/" + path[len(prefix) + 1 :]
    return path


def crawled_output_file_count(
    raw_dir: str,
    artifact_store: LocalArtifactStore,
) -> int:
    """Count actual content files, excluding crawler bookkeeping."""

    root = artifact_store.resolve(raw_dir)
    if not root.is_dir():
        return 0
    count = 0
    for path in root.rglob("*"):
        if not path.is_file() or path.name in SCRAPER_BOOKKEEPING_FILENAMES:
            continue
        resolved = artifact_store.resolve(path)
        artifact_store.relative_path(resolved, root)
        count += 1
    return count


def positive_int(value: object) -> int | None:
    """Return a positive integer without treating booleans as counts."""

    if isinstance(value, bool):
        return None
    if isinstance(value, (int, float)):
        integer = int(value)
        return integer if integer > 0 else None
    if isinstance(value, str) and value.strip().isdigit():
        integer = int(value.strip())
        return integer if integer > 0 else None
    return None


def normalize_status(payload: dict[str, Any]) -> str:
    """Normalize external service terminal-state vocabulary."""

    status = str(payload.get("status") or "running").strip().lower()
    if status in {"completed", "complete", "succeeded", "success", "done", "ready"}:
        return "success"
    if status in {"failed", "error", "timeout", "cancelled", "canceled"}:
        return "failed"
    return status
