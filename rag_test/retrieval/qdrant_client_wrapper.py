from __future__ import annotations

import json
import logging
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any

logger = logging.getLogger(__name__)


@dataclass(slots=True)
class QdrantClientWrapper:
    """Minimal Qdrant HTTP client used for collection-level benchmark bookkeeping."""
    base_url: str
    api_key: str = ""
    timeout_seconds: int = 30

    def _request(self, method: str, path: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
        """Send one raw Qdrant request and return decoded JSON."""
        logger.info(
            "qdrant_wrapper._request start method=%s path=%s has_payload=%s timeout=%s",
            method,
            path,
            payload is not None,
            self.timeout_seconds,
        )
        url = urllib.parse.urljoin(self.base_url.rstrip("/") + "/", path.lstrip("/"))
        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["api-key"] = self.api_key
        data = None if payload is None else json.dumps(payload).encode("utf-8")
        request = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(request, timeout=self.timeout_seconds) as response:
                raw = response.read().decode("utf-8")
            logger.info("qdrant_wrapper._request success method=%s path=%s", method, path)
            return json.loads(raw) if raw else {}
        except Exception as exc:
            logger.exception("qdrant_wrapper._request failed method=%s path=%s error=%s", method, path, exc)
            raise

    def recreate_collection(self, collection_name: str, vector_size: int, distance: str) -> None:
        """Drop and recreate a collection so each benchmark model starts from a clean index."""
        logger.info(
            "qdrant_wrapper.recreate_collection start collection=%s vector_size=%s distance=%s",
            collection_name,
            vector_size,
            distance,
        )
        try:
            self.delete_collection(collection_name, ignore_missing=True)
            self._request(
                "PUT",
                f"/collections/{collection_name}",
                {
                    "vectors": {
                        "size": vector_size,
                        "distance": distance,
                    }
                },
            )
            logger.info("qdrant_wrapper.recreate_collection success collection=%s", collection_name)
        except Exception as exc:
            logger.exception("qdrant_wrapper.recreate_collection failed collection=%s error=%s", collection_name, exc)
            raise

    def delete_collection(self, collection_name: str, ignore_missing: bool = False) -> None:
        """Delete one collection, optionally treating missing collections as non-fatal."""
        logger.info(
            "qdrant_wrapper.delete_collection start collection=%s ignore_missing=%s",
            collection_name,
            ignore_missing,
        )
        try:
            self._request("DELETE", f"/collections/{collection_name}")
            logger.info("qdrant_wrapper.delete_collection success collection=%s", collection_name)
        except Exception as exc:
            logger.exception("qdrant_wrapper.delete_collection failed collection=%s error=%s", collection_name, exc)
            if not ignore_missing:
                raise

    def upsert_points(self, collection_name: str, points: list[dict[str, Any]]) -> None:
        """Insert a batch of points into a benchmark collection."""
        logger.info(
            "qdrant_wrapper.upsert_points start collection=%s points=%s",
            collection_name,
            len(points),
        )
        try:
            self._request(
                "PUT",
                f"/collections/{collection_name}/points?wait=true",
                {"points": points},
            )
            logger.info("qdrant_wrapper.upsert_points success collection=%s", collection_name)
        except Exception as exc:
            logger.exception("qdrant_wrapper.upsert_points failed collection=%s error=%s", collection_name, exc)
            raise

    def search(self, collection_name: str, vector: list[float], limit: int) -> list[dict[str, Any]]:
        """Run a direct vector search against one named collection."""
        logger.info(
            "qdrant_wrapper.search start collection=%s limit=%s vector_dim=%s",
            collection_name,
            limit,
            len(vector),
        )
        try:
            response = self._request(
                "POST",
                f"/collections/{collection_name}/points/search",
                {
                    "vector": vector,
                    "limit": limit,
                    "with_payload": True,
                },
            )
            result = response.get("result", [])
            logger.info(
                "qdrant_wrapper.search success collection=%s hits=%s",
                collection_name,
                len(result),
            )
            return result
        except Exception as exc:
            logger.exception("qdrant_wrapper.search failed collection=%s error=%s", collection_name, exc)
            raise

    def collection_info(self, collection_name: str) -> dict[str, Any]:
        """Fetch collection metadata for reporting and verification."""
        logger.info("qdrant_wrapper.collection_info start collection=%s", collection_name)
        try:
            response = self._request("GET", f"/collections/{collection_name}")
            result = response.get("result", {})
            logger.info("qdrant_wrapper.collection_info success collection=%s", collection_name)
            return result
        except Exception as exc:
            logger.exception("qdrant_wrapper.collection_info failed collection=%s error=%s", collection_name, exc)
            raise

    def collection_count(self, collection_name: str) -> int:
        """Return the exact point count for one benchmark collection."""
        logger.info("qdrant_wrapper.collection_count start collection=%s", collection_name)
        try:
            response = self._request(
                "POST",
                f"/collections/{collection_name}/points/count",
                {"exact": True},
            )
            count = int(response.get("result", {}).get("count", 0))
            logger.info("qdrant_wrapper.collection_count success collection=%s count=%s", collection_name, count)
            return count
        except Exception as exc:
            logger.exception("qdrant_wrapper.collection_count failed collection=%s error=%s", collection_name, exc)
            raise
