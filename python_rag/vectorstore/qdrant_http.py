import logging
import os
import time
from typing import List, Dict, Any, Optional

import requests
from requests import RequestException, Response

logger = logging.getLogger(__name__)


class QdrantHTTP:
    """Lightweight client used by the FastAPI bridge to talk to Qdrant."""
    def __init__(self) -> None:
        scheme = os.environ.get("QDRANT_SCHEME", "http")
        host = os.environ.get("QDRANT_HOST", "qdrant")
        port = int(os.environ.get("QDRANT_PORT", "6333"))
        self.collection = os.environ.get("QDRANT_COLLECTION", "").strip()
        self.base = f"{scheme}://{host}:{port}"
        self.api_key = os.environ.get("QDRANT_API_KEY")
        self.timeout = float(os.environ.get("QDRANT_TIMEOUT", "30"))
        self._session = requests.Session()
        self.max_attempts = int(os.environ.get("QDRANT_RETRY_ATTEMPTS", "3"))

    def _headers(self) -> Dict[str, str]:
        """Return default headers including an optional API key."""
        h = {"Content-Type": "application/json"}
        if self.api_key:
            h["api-key"] = self.api_key
        return h

    def _request(self, method: str, path: str, **kwargs) -> Response:
        """Fire a request with exponential backoff and optional latency logging."""
        url = f"{self.base}{path}"
        kwargs.setdefault("headers", self._headers())
        kwargs.setdefault("timeout", self.timeout)

        log_latency = os.environ.get("QDRANT_LOG_LATENCY", "false").lower() in ("1", "true", "yes")
        backoff = 0.5
        attempt = 0
        while True:
            attempt += 1
            try:
                start = time.perf_counter()
                response = self._session.request(method, url, **kwargs)
                elapsed = time.perf_counter() - start
                if log_latency:
                    logger.info(
                        "Qdrant %s %s succeeded in %.3fs", method.upper(), path, elapsed
                    )
                if response.status_code >= 500:
                    logger.warning(
                        "Qdrant %s %s failed with %s",
                        method.upper(),
                        path,
                        response.status_code,
                    )
                    response.raise_for_status()
                return response
            except RequestException as exc:
                if attempt >= self.max_attempts:
                    raise
                logger.warning("Qdrant request error (%s). Retrying...", exc)
                time.sleep(backoff)
                backoff = min(backoff * 2, 5.0)

    def ensure_collection(self, vector_size: int, distance: str = "Cosine") -> None:
        """Create the collection if it does not already exist."""
        r = self._request("GET", f"/collections/{self.collection}")
        if r.status_code == 200:
            return
        payload = {"vectors": {"size": int(vector_size), "distance": distance}}
        rc = self._request("PUT", f"/collections/{self.collection}", json=payload)
        rc.raise_for_status()

    def list_collections(self) -> List[str]:
        """Return all collection names available in Qdrant."""
        r = self._request("GET", "/collections")
        r.raise_for_status()
        data = r.json().get("result", {}) or {}
        names = []
        for col in data.get("collections", []) or []:
            name = col.get("name")
            if name:
                names.append(str(name))
        return names

    def _pick_default_collection(self) -> Optional[str]:
        """Pick the most populated collection when no default is configured."""
        try:
            names = self.list_collections()
        except Exception as exc:
            logger.warning("Qdrant default selection failed to list collections: %s", exc)
            return None
        best_name = None
        best_count = -1
        for name in names:
            count = self.count_points(name)
            if count is None:
                continue
            if count > best_count:
                best_name = name
                best_count = count
        return best_name

    def _search_collection(
        self,
        collection: str,
        body: Dict[str, Any],
        timeout: float,
    ) -> List[Dict[str, Any]]:
        r = self._request(
            "POST",
            f"/collections/{collection}/points/search",
            json=body,
            timeout=timeout,
        )
        if r.status_code == 404:
            return []
        r.raise_for_status()
        return r.json().get("result", [])

    def upsert(self, points: List[Dict[str, Any]]) -> None:
        """Upsert batches of points into the chosen collection."""
        if not points:
            return
        r = self._request(
            "PUT",
            f"/collections/{self.collection}/points",
            json={"points": points},
            timeout=float(os.environ.get("QDRANT_UPSERT_TIMEOUT", self.timeout)),
        )
        if r.status_code >= 400:
            logger.error("Qdrant upsert failed status=%s body=%s", r.status_code, r.text)
        r.raise_for_status()

    def upsert_points(self, points: List[Dict[str, Any]], *, batch_size: int = 64) -> None:
        """Upsert points in batches."""
        if not points:
            return
        size = max(1, int(batch_size))
        logger.info("qdrant:upsert_points total=%s batch_size=%s", len(points), size)
        for i in range(0, len(points), size):
            self.upsert(points[i:i + size])

    def count_points(self, collection: Optional[str] = None, exact: bool = True) -> Optional[int]:
        """Return the number of points stored in a collection."""
        col = collection or self.collection
        body = {"exact": bool(exact)}
        try:
            r = self._request(
                "POST",
                f"/collections/{col}/points/count",
                json=body,
                timeout=float(os.environ.get("QDRANT_COUNT_TIMEOUT", self.timeout)),
            )
            r.raise_for_status()
            result = r.json().get("result") or {}
            count = result.get("count")
            return int(count) if count is not None else None
        except RequestException as exc:
            status = getattr(getattr(exc, "response", None), "status_code", None)
            if status == 404:
                return None
            logger.warning("Qdrant count failed for collection %s: %s", col, exc)
            return None

    def search(
        self,
        vector: List[float],
        top_k: int = 5,
        filters: Optional[Dict[str, Any]] = None,
        *,
        score_threshold: Optional[float] = None,
        params: Optional[Dict[str, Any]] = None,
        with_payload: bool = True,
        with_vector: bool = False,
        payload_projection: Optional[List[str]] = None,
        keyword_terms: Optional[List[str]] = None,
        keyword_fields: Optional[List[str]] = None,
    ) -> List[Dict[str, Any]]:
        """Execute a vector search and return payload-rich results."""
        timeout = float(os.environ.get("QDRANT_SEARCH_TIMEOUT", self.timeout))
        search_all = os.environ.get("QDRANT_SEARCH_ALL", "false").lower() in ("1", "true", "yes")
        body: Dict[str, Any] = {
            "vector": vector,
            "limit": int(top_k),
            "with_payload": with_payload,
            "with_vector": with_vector,
        }
        if filters:
            body["filter"] = {"must": [{"key": k, "match": {"value": v}} for k, v in filters.items()]}
        if keyword_terms and keyword_fields:
            should = []
            for term in keyword_terms[:6]:
                for field in keyword_fields:
                    should.append({"key": field, "match": {"value": term}})
            if should:
                body.setdefault("filter", {})
                body["filter"].setdefault("should", [])
                body["filter"]["should"].extend(should)
        if score_threshold is not None:
            body["score_threshold"] = float(score_threshold)
        if params:
            body["params"] = params
        if payload_projection:
            body["with_payload"] = {"include": payload_projection}

        if search_all:
            try:
                collections = self.list_collections()
            except Exception as exc:
                logger.warning("Qdrant search-all failed to list collections: %s", exc)
                collections = []
            if not collections:
                return []
            max_per_collection = int(os.environ.get("QDRANT_SEARCH_ALL_PER_COLLECTION", str(top_k)))
            merged: List[Dict[str, Any]] = []
            for name in collections:
                body["limit"] = int(max_per_collection)
                results = self._search_collection(name, body, timeout)
                for hit in results:
                    if isinstance(hit, dict) and "collection" not in hit:
                        hit["collection"] = name
                merged.extend(results)
            merged.sort(key=lambda h: float(h.get("score") or 0.0), reverse=True)
            return merged[: int(top_k)]

        collection = self.collection
        if not collection:
            collection = self._pick_default_collection() or ""
            self.collection = collection

        r = self._request(
            "POST",
            f"/collections/{collection}/points/search",
            json=body,
            timeout=timeout,
        )
        if r.status_code != 404:
            r.raise_for_status()
            j = r.json()
            return j.get("result", [])

        fallback_enabled = os.environ.get("QDRANT_FALLBACK_ALL", "true").lower() in ("1", "true", "yes")
        if not fallback_enabled:
            r.raise_for_status()
            return []

        try:
            collections = self.list_collections()
        except Exception as exc:
            logger.warning("Qdrant collection fallback failed to list collections: %s", exc)
            r.raise_for_status()
            return []

        max_per_collection = int(os.environ.get("QDRANT_FALLBACK_PER_COLLECTION", str(top_k)))
        merged: List[Dict[str, Any]] = []
        for name in collections:
            body["limit"] = int(max_per_collection)
            results = self._search_collection(name, body, timeout)
            for hit in results:
                if isinstance(hit, dict) and "collection" not in hit:
                    hit["collection"] = name
            merged.extend(results)

        merged.sort(key=lambda h: float(h.get("score") or 0.0), reverse=True)
        return merged[: int(top_k)]

    def delete_by_filter(self, filter_body: Dict[str, Any]) -> Dict[str, Any]:
        """Delete points matching the supplied Qdrant filter."""
        payload = {"filter": filter_body}
        r = self._request(
            "POST",
            f"/collections/{self.collection}/points/delete",
            json=payload,
            timeout=float(os.environ.get("QDRANT_DELETE_TIMEOUT", self.timeout)),
        )
        r.raise_for_status()
        return r.json()

    def delete_by_doc_id(self, doc_id: str) -> Dict[str, Any]:
        """Delete all points that belong to the provided document id."""
        filt = {"must": [{"key": "doc_id", "match": {"value": str(doc_id)}}]}
        return self.delete_by_filter(filt)

    def get_collection_config(self) -> Dict[str, Any]:
        """Fetch the collection configuration from Qdrant."""
        r = self._request("GET", f"/collections/{self.collection}")
        r.raise_for_status()
        return r.json().get("result", {})

    def get_vector_size(self) -> Optional[int]:
        """Read the configured vector size; return None if it cannot be derived."""
        try:
            cfg = self.get_collection_config()
            params = (cfg.get("config") or {}).get("params") or {}
            vectors = params.get("vectors") or {}
            # single-vector schema
            if isinstance(vectors, dict) and "size" in vectors:
                return int(vectors.get("size"))
            # named multi-vector schema
            if isinstance(vectors, dict) and isinstance(vectors.get("params"), dict):
                first = next(iter(vectors["params"].values()), None)
                if isinstance(first, dict) and "size" in first:
                    return int(first["size"])
        except Exception:
            return None
        return None
