"""Read-only Qdrant collection catalog for query-dataset visibility.

A catalog failure is never an empty catalog. ``None`` means availability
could not be established, so callers fail closed and expose no dataset backed
by an unknown collection.
"""

from __future__ import annotations

import logging
from urllib.parse import quote

import httpx

logger = logging.getLogger(__name__)


class QdrantCatalog:
    """Answer the two read-only questions dataset visibility needs."""

    def __init__(
        self,
        base_url: str,
        api_key: str = "",
        *,
        timeout_seconds: float = 3.0,
    ) -> None:
        self._base_url = base_url.rstrip("/")
        self._api_key = api_key.strip()
        self._timeout = timeout_seconds

    def available_collections(self) -> set[str] | None:
        """Return the exact collection names, or ``None`` when unknown."""

        try:
            with self._client() as client:
                response = client.get(self._base_url + "/collections")
            if response.status_code != 200:
                logger.warning(
                    "Qdrant collection catalog request failed status=%s",
                    response.status_code,
                )
                return None
            body = response.json()
        except (httpx.HTTPError, ValueError, TypeError):
            logger.warning("Qdrant collection catalog request failed.")
            return None
        result = body.get("result") if isinstance(body, dict) else None
        collections = result.get("collections") if isinstance(result, dict) else None
        if not isinstance(collections, list):
            logger.warning("Qdrant collection catalog returned an invalid response.")
            return None
        return {
            str(item["name"]).strip()
            for item in collections
            if isinstance(item, dict)
            and isinstance(item.get("name"), str)
            and item["name"].strip()
        }

    def point_count(self, collection: str) -> int | None:
        """Return the exact point count of a collection, or ``None``."""

        try:
            with self._client() as client:
                response = client.post(
                    self._base_url
                    + "/collections/"
                    + quote(collection, safe="")
                    + "/points/count",
                    json={"exact": True},
                )
            if response.status_code != 200:
                return None
            body = response.json()
        except (httpx.HTTPError, ValueError, TypeError):
            logger.warning("Qdrant point count request failed.")
            return None
        result = body.get("result") if isinstance(body, dict) else None
        count = result.get("count") if isinstance(result, dict) else None
        return count if isinstance(count, int) and count >= 0 else None

    def _client(self) -> httpx.Client:
        headers = {"api-key": self._api_key} if self._api_key else {}
        return httpx.Client(
            timeout=self._timeout,
            follow_redirects=False,
            headers=headers,
        )


__all__ = ["QdrantCatalog"]
