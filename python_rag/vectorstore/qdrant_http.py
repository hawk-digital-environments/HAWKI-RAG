import logging
import os
import time
from typing import List, Dict, Any, Optional

import requests
from requests import RequestException, Response
from vectorstore.collections import collection_names, pick_most_populated_collection, vector_size_from_config
from vectorstore.payloads import (
    build_delete_filter,
    build_scroll_body,
    build_search_body,
    build_text_filter,
    build_vector_search_body,
    iter_batches,
)
from vectorstore.settings import qdrant_settings_from_env

logger = logging.getLogger(__name__)


class QdrantHTTP:
    """Lightweight client used by the FastAPI bridge to talk to Qdrant."""
    def __init__(self) -> None:
        settings = qdrant_settings_from_env()
        self.collection = settings.collection
        self.base = settings.base_url
        self.api_key = settings.api_key
        self.timeout = settings.timeout
        self._session = requests.Session()
        self.max_attempts = settings.max_attempts

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
        return collection_names(r.json())

    def _pick_default_collection(self) -> Optional[str]:
        """Pick the most populated collection when no default is configured."""
        try:
            names = self.list_collections()
        except Exception as exc:
            logger.warning("Qdrant default selection failed to list collections: %s", exc)
            return None
        return pick_most_populated_collection((name, self.count_points(name)) for name in names)

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
        for batch in iter_batches(points, size):
            self.upsert(batch)

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
        body = build_search_body(
            vector,
            top_k=top_k,
            filters=filters,
            score_threshold=score_threshold,
            params=params,
            with_payload=with_payload,
            with_vector=with_vector,
            payload_projection=payload_projection,
            keyword_terms=keyword_terms,
            keyword_fields=keyword_fields,
        )

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

    def search_with_text(
        self,
        vector: List[float],
        *,
        top_k: int,
        terms: List[str],
        fields: List[str],
    ) -> List[Dict[str, Any]]:
        terms = [t for t in (terms or []) if t]
        fields = [f for f in (fields or []) if f]
        if not terms or not fields:
            return []
        collection = self.collection
        if not collection:
            collection = self._pick_default_collection() or ""
            self.collection = collection
        if not collection:
            return []
        max_terms = int(os.environ.get("QDRANT_TEXT_FALLBACK_TERMS", "3"))
        filter_body = build_text_filter(terms, fields, max_terms=max_terms, require_all=True)
        if not filter_body:
            return []
        body = build_vector_search_body(vector, top_k=top_k, filter_body=filter_body)
        r = self._request(
            "POST",
            f"/collections/{collection}/points/search",
            json=body,
            timeout=float(os.environ.get("QDRANT_SEARCH_TIMEOUT", self.timeout)),
        )
        if r.status_code == 404:
            return []
        r.raise_for_status()
        result = r.json().get("result", [])
        if result:
            return result

        # Relax to "any term" if strict matching yields nothing.
        relax_body = build_vector_search_body(
            vector,
            top_k=top_k,
            filter_body=build_text_filter(terms, fields, max_terms=max_terms, require_all=False),
        )
        r2 = self._request(
            "POST",
            f"/collections/{collection}/points/search",
            json=relax_body,
            timeout=float(os.environ.get("QDRANT_SEARCH_TIMEOUT", self.timeout)),
        )
        if r2.status_code == 404:
            return []
        r2.raise_for_status()
        return r2.json().get("result", [])

    def scroll_with_text(
        self,
        *,
        terms: List[str],
        fields: List[str],
        limit: int,
        require_all: bool = True,
        offset: Optional[str] = None,
    ) -> List[Dict[str, Any]]:
        terms = [t for t in (terms or []) if t]
        fields = [f for f in (fields or []) if f]
        if not terms or not fields:
            return []
        collection = self.collection
        if not collection:
            collection = self._pick_default_collection() or ""
            self.collection = collection
        if not collection:
            return []
        max_terms = int(os.environ.get("QDRANT_TEXT_FALLBACK_TERMS", "3"))
        filter_body = build_text_filter(terms, fields, max_terms=max_terms, require_all=require_all)
        if not filter_body:
            return []
        body = build_scroll_body(limit=limit, filter_body=filter_body, offset=offset)
        r = self._request(
            "POST",
            f"/collections/{collection}/points/scroll",
            json=body,
            timeout=float(os.environ.get("QDRANT_SEARCH_TIMEOUT", self.timeout)),
        )
        if r.status_code == 404:
            return []
        r.raise_for_status()
        result = r.json().get("result") or {}
        return result.get("points") or []

    def scroll_with_text_all(
        self,
        *,
        terms: List[str],
        fields: List[str],
        limit: int,
        require_all: bool = True,
    ) -> List[Dict[str, Any]]:
        terms = [t for t in (terms or []) if t]
        fields = [f for f in (fields or []) if f]
        if not terms or not fields:
            return []
        collection = self.collection
        if not collection:
            collection = self._pick_default_collection() or ""
            self.collection = collection
        if not collection:
            return []
        max_terms = int(os.environ.get("QDRANT_TEXT_FALLBACK_TERMS", "3"))
        filter_body = build_text_filter(terms, fields, max_terms=max_terms, require_all=require_all)
        if not filter_body:
            return []
        hard_cap = int(os.environ.get("QDRANT_TEXT_SCROLL_HARD_CAP", "50000"))
        cap = int(limit) if limit is not None else 0
        if cap <= 0:
            cap = hard_cap
        else:
            cap = min(cap, hard_cap)
        batch_size = int(os.environ.get("QDRANT_TEXT_SCROLL_BATCH", "256"))
        batch_size = max(1, min(batch_size, cap))
        collected: List[Dict[str, Any]] = []
        offset: Optional[str] = None
        while len(collected) < cap:
            body = build_scroll_body(
                limit=int(min(batch_size, cap - len(collected))),
                filter_body=filter_body,
                offset=offset,
            )
            r = self._request(
                "POST",
                f"/collections/{collection}/points/scroll",
                json=body,
                timeout=float(os.environ.get("QDRANT_SEARCH_TIMEOUT", self.timeout)),
            )
            if r.status_code == 404:
                break
            r.raise_for_status()
            result = r.json().get("result") or {}
            points = result.get("points") or []
            if not points:
                break
            collected.extend(points)
            next_offset = result.get("next_page_offset")
            if not next_offset:
                break
            offset = next_offset
        return collected

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
        return self.delete_by_filter(build_delete_filter(doc_id))

    def get_collection_config(self) -> Dict[str, Any]:
        """Fetch the collection configuration from Qdrant."""
        r = self._request("GET", f"/collections/{self.collection}")
        r.raise_for_status()
        return r.json().get("result", {})

    def get_vector_size(self) -> Optional[int]:
        """Read the configured vector size; return None if it cannot be derived."""
        try:
            cfg = self.get_collection_config()
            return vector_size_from_config(cfg)
        except Exception:
            return None
