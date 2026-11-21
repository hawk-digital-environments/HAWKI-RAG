"""Sanitized crawler profile/task catalog for the existing management UI."""

import os

from fastapi import HTTPException, Request
import httpx

from hawki_backend.deployment import http_url
from .api import Principal, router, service
from .errors import UrlIngestionError

SETTING_KEYS = (
    "max_pages",
    "max_concurrency",
    "max_rpm",
    "skip_images",
    "discovery_mode",
    "rescrape_failed",
    "max_images_per_page",
    "max_link_density",
    "wait_until",
    "page_timeout_ms",
)


def entries(value, key):
    if isinstance(value, dict):
        value = value.get(key, value.get("data", {}).get(key, []))
    if not isinstance(value, list):
        raise ValueError("Invalid catalog")
    return [row for row in value if isinstance(row, dict)]


def normalize(profiles, tasks):
    catalog = {}
    for row in profiles:
        identity = row.get("name")
        if not isinstance(identity, str) or not identity:
            continue
        profile = row.get("profile", {})
        hosts = [
            host
            for host in row.get("match_hosts", [])
            if isinstance(host, str) and not host.startswith("*.")
        ]
        sitemap = profile.get("sitemap", {}).get("base_url")
        catalog[identity] = {
            "profileId": identity,
            "profileName": profile.get("name") or identity,
            "primaryUrl": "https://" + hosts[0] if hosts else sitemap,
            "sitemapUrl": sitemap,
            "containerPath": row.get("containerPath"),
            "settings": {key: profile[key] for key in SETTING_KEYS if key in profile},
        }
    output = {
        "manual-" + key: {
            **profile,
            "id": "manual-" + key,
            "label": profile["profileName"] + " Manual Crawl",
            "schedule": None,
            "type": "manual",
            "source": "scraper-task-ui",
        }
        for key, profile in catalog.items()
    }
    for row in tasks:
        identity = row.get("id")
        profile = catalog.get(row.get("profileId") or row.get("profile_id"))
        if isinstance(identity, str) and identity and profile:
            output[identity] = {
                **profile,
                "id": identity,
                "label": row.get("name") or identity,
                "schedule": row.get("schedule"),
                "type": "scheduled" if row.get("schedule") else "manual",
                "source": "scraper-task-ui",
            }
    return sorted(output.values(), key=lambda item: item["label"].lower())


@router.get("/scraper/tasks", include_in_schema=False)
def catalog(request: Request, principal: Principal):
    instance = service(request)
    base = os.environ.get(
        "CUSTOM_CRAWLER_TASK_UI_URL", instance.settings.scraper_url
    ).rstrip("/")
    if not http_url(base):
        raise HTTPException(503, "crawler_catalog_unavailable")
    try:
        with httpx.Client(timeout=5, follow_redirects=False, trust_env=False) as client:
            values = []
            for key, default in (
                ("PROFILES", "/ui/api/profiles"),
                ("TASKS", "/ui/api/tasks"),
            ):
                path = os.environ.get(
                    "CUSTOM_CRAWLER_TASK_UI_" + key + "_PATH", default
                )
                response = client.get(
                    base + "/" + path.lstrip("/"),
                    headers={
                        "Authorization": "Bearer " + instance.settings.scraper_token
                    }
                    if instance.settings.scraper_token
                    else {},
                )
                if (
                    key == "PROFILES"
                    and response.status_code == 404
                    and path == default
                    and base == instance.settings.scraper_url.rstrip("/")
                ):
                    # Saved profiles belong to the optional crawler UI, not
                    # permanent-job-v2. Confirm the real API is healthy before
                    # reporting an absent catalog instead of hiding an outage.
                    instance.crawler.require_safe_fetch([])
                    return {
                        "success": True,
                        "tasks": [],
                        "message": "This crawler has no saved profile/task catalog. URL ingestion remains available through the pipeline API.",
                    }
                if key == "TASKS" and response.status_code == 404:
                    values.append([])
                    continue
                response.raise_for_status()
                if len(response.content) > 2 * 1024 * 1024:
                    raise ValueError("Catalog too large")
                values.append(entries(response.json(), key.lower()))
        return {"success": True, "tasks": normalize(*values)}
    except (
        httpx.HTTPError,
        ValueError,
        TypeError,
        AttributeError,
        KeyError,
        UrlIngestionError,
    ):
        raise HTTPException(503, "crawler_catalog_unavailable") from None
