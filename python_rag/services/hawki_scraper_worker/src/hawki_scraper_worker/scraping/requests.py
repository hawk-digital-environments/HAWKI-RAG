"""Build the existing external crawler start payload."""

from __future__ import annotations

from typing import Any


def build_scraper_start_payload(
    workflow_input: dict[str, Any],
    source_id: str,
    raw_dir: str,
) -> dict[str, Any]:
    """Translate a workflow payload into the external crawler contract."""

    metadata = workflow_input.get("metadata")
    request = workflow_input.get("request")
    if not isinstance(request, dict) and isinstance(metadata, dict):
        request = metadata.get("request")
    if not isinstance(request, dict):
        request = {}

    request_metadata = request.get("metadata")
    if not isinstance(request_metadata, dict):
        request_metadata = {}

    payload: dict[str, Any] = {
        "job_id": str(workflow_input.get("job_id") or source_id),
        "url": str(workflow_input.get("source_url") or ""),
        "output_dir": raw_dir,
        "source_id": source_id,
        "source_url": workflow_input.get("source_url"),
    }

    if request_metadata.get("site_profile_path"):
        payload["site_profile_path"] = request_metadata["site_profile_path"]

    sitemap_url = _string_value(
        request.get("sitemapUrl")
        or request.get("sitemap_url")
        or request_metadata.get("sitemap_url")
    )
    if sitemap_url:
        payload["sitemap"] = True
        payload["sitemap_base"] = sitemap_url

    for key in (
        "rescrape_failed",
        "max_pages",
        "max_concurrency",
        "max_rpm",
        "skip_images",
        "max_images_per_page",
        "max_link_density",
        "discovery_mode",
        "wait_until",
        "page_timeout_ms",
    ):
        if key in request_metadata and request_metadata[key] is not None:
            payload[key] = request_metadata[key]

    return payload


def _string_value(value: object) -> str:
    if isinstance(value, (str, int, float)) and str(value).strip():
        return str(value).strip()
    return ""


__all__ = ["build_scraper_start_payload"]
