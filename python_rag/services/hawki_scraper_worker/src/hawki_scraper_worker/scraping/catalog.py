"""Read and sanitize the external crawler's optional saved task catalog."""

import os

from hawki_external_jobs.crawler import CustomCrawlerClient

from .client import crawler_endpoint_id


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


def read_catalog(crawler: CustomCrawlerClient) -> dict:
    scraper_url = crawler.base_url.rstrip("/")
    base = os.environ.get("CUSTOM_CRAWLER_TASK_UI_URL", "").rstrip("/") or scraper_url
    crawler_endpoint_id(base)
    headers = {"Authorization": f"Bearer {crawler.token}"} if crawler.token else {}
    values = []
    for key, default in (
        ("PROFILES", "/ui/api/profiles"),
        ("TASKS", "/ui/api/tasks"),
    ):
        path = os.environ.get(f"CUSTOM_CRAWLER_TASK_UI_{key}_PATH", default)
        response = crawler.session.get(
            base + "/" + path.lstrip("/"),
            headers=headers,
            timeout=crawler.timeout_seconds,
            allow_redirects=False,
        )
        if response.status_code == 404:
            if key == "TASKS":
                values.append([])
                continue
            if path == default and base == scraper_url:
                crawler.require_capability()
                return {
                    "success": True,
                    "tasks": [],
                    "message": (
                        "This crawler has no saved profile/task catalog. "
                        "URL ingestion remains available through the pipeline API."
                    ),
                }
        if (
            not 200 <= response.status_code < 300
            or len(response.content) > 2 * 1024 * 1024
        ):
            raise ValueError("Invalid crawler catalog response")
        values.append(entries(response.json(), key.lower()))
    return {"success": True, "tasks": normalize(*values)}
