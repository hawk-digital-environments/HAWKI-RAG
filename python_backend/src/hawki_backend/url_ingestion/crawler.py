"""Control-plane compatibility with the reviewed external execution runtime."""

import httpx

from hawki_rag_contracts.pipeline.crawler import require_crawler_api

from .errors import UrlIngestionError


class ExternalCrawlerBoundary:
    def __init__(self, url: str = "", token: str = "", *, client_factory=httpx.Client):
        self.url = url
        self.token = token
        self.client_factory = client_factory

    def require_safe_fetch(self, urls) -> None:
        if not self.url:
            raise UrlIngestionError(503, "CustomCrawler is not configured.")
        try:
            with self.client_factory(
                timeout=5,
                follow_redirects=False,
                trust_env=False,
                headers={"Authorization": "Bearer " + self.token} if self.token else {},
            ) as client:
                schema = client.get(self.url + "/openapi.json")
                schema.raise_for_status()
                require_crawler_api(schema.json())
                health = client.get(self.url + "/health")
                health.raise_for_status()
                if health.json().get("status") != "healthy":
                    raise ValueError("Crawler unavailable")
        except (httpx.HTTPError, ValueError, TypeError, AttributeError):
            raise UrlIngestionError(
                503, "CustomCrawler is unavailable or lacks the permanent-job contract."
            ) from None
