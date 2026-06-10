from __future__ import annotations

import logging
from typing import Any, Dict, List, Optional

import requests
from requests import RequestException, Response

from vectorstore.collections import pick_most_populated_collection, vector_size_from_config
from vectorstore.payloads import (
    build_delete_filter,
    build_scroll_body,
    build_search_body,
    build_text_filter,
    build_vector_search_body,
    iter_batches,
)
from vectorstore.settings import (
    QdrantHTTPSettings,
    QdrantSettings,
    qdrant_http_settings_from_env,
    qdrant_settings_from_env,
)
from vectorstore.qdrant_requests import (
    QdrantRequest,
    build_count_points_request,
    build_create_collection_request,
    build_delete_by_filter_request,
    build_get_collection_request,
    build_list_collections_request,
    build_scroll_request,
    build_search_request,
    build_upsert_points_request,
)
from vectorstore.qdrant_responses import (
    SearchResultList,
    parse_collection_config,
    parse_collection_names,
    parse_count,
    parse_scroll_points,
    parse_search_result,
)
from vectorstore.qdrant_search import (
    normalize_query_inputs,
    resolve_collection_with_default,
    search_with_fallback_collections,
)
from vectorstore.qdrant_transport import QdrantHTTPTransport

logger = logging.getLogger(__name__)


def _resolve_per_collection_limit(requested_limit: int, fallback_limit: int) -> int:
    """Preserve legacy behavior where empty env values used `top_k`."""
    if requested_limit > 0:
        return requested_limit
    return fallback_limit


class QdrantHTTP:
    """Lightweight client used by the FastAPI bridge to talk to Qdrant."""

    def __init__(
        self,
        settings: Optional[QdrantSettings] = None,
        http_settings: Optional[QdrantHTTPSettings] = None,
    ) -> None:
        qdrant_settings = settings or qdrant_settings_from_env()
        self.collection = qdrant_settings.collection
        base = qdrant_settings.base_url
        self.timeout = qdrant_settings.timeout
        self._http_settings = http_settings or qdrant_http_settings_from_env(
            base_timeout=qdrant_settings.timeout
        )
        self._transport = QdrantHTTPTransport(
            base_url=base,
            api_key=qdrant_settings.api_key,
            default_timeout=self.timeout,
            max_attempts=qdrant_settings.max_attempts,
            log_latency=self._http_settings.log_latency,
            session=requests.Session(),
        )

    def _request(self, request: QdrantRequest) -> Response:
        """Execute one request through the transport adapter."""
        return self._transport.send(request)

    def ensure_collection(self, vector_size: int, distance: str = "Cosine") -> None:
        """Create the collection if it does not already exist."""
        r = self._request(build_get_collection_request(self.collection))
        if r.status_code == 200:
            return
        rc = self._request(
            build_create_collection_request(
                self.collection,
                vector_size=vector_size,
                distance=distance,
            )
        )
        rc.raise_for_status()

    def list_collections(self) -> List[str]:
        """Return all collection names available in Qdrant."""
        r = self._request(build_list_collections_request())
        r.raise_for_status()
        return parse_collection_names(r.json())

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
    ) -> SearchResultList:
        r = self._request(
            build_search_request(
                collection,
                body,
                timeout=timeout,
            )
        )
        if r.status_code == 404:
            return []
        r.raise_for_status()
        return parse_search_result(r.json())

    def upsert(self, points: List[Dict[str, Any]]) -> None:
        """Upsert batches of points into the chosen collection."""
        if not points:
            return
        r = self._request(
            build_upsert_points_request(
                self.collection,
                points,
                timeout=self._http_settings.upsert_timeout,
            )
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
        try:
            r = self._request(
                build_count_points_request(
                    col,
                    exact=exact,
                    timeout=self._http_settings.count_timeout,
                )
            )
            r.raise_for_status()
            return parse_count(r.json())
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
        timeout = self._http_settings.search_timeout
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

        if self._http_settings.search_all:
            try:
                collections = self.list_collections()
            except Exception as exc:
                logger.warning("Qdrant search-all failed to list collections: %s", exc)
                collections = []
            if not collections:
                return []
            max_per_collection = _resolve_per_collection_limit(
                self._http_settings.search_all_per_collection,
                top_k,
            )
            return search_with_fallback_collections(
                collections,
                body,
                timeout=timeout,
                top_k=top_k,
                per_collection_limit=max_per_collection,
                execute=self._search_collection,
            )

        self.collection = resolve_collection_with_default(
            self.collection,
            lambda: self._pick_default_collection() or "",
        )
        collection = self.collection

        r = self._request(
            build_search_request(
                collection,
                body,
                timeout=timeout,
            )
        )
        if r.status_code != 404:
            r.raise_for_status()
            return parse_search_result(r.json())

        fallback_enabled = self._http_settings.fallback_all
        if not fallback_enabled:
            r.raise_for_status()
            return []

        try:
            collections = self.list_collections()
        except Exception as exc:
            logger.warning("Qdrant collection fallback failed to list collections: %s", exc)
            r.raise_for_status()
            return []

        max_per_collection = _resolve_per_collection_limit(
            self._http_settings.fallback_per_collection,
            top_k,
        )
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
        self.collection = resolve_collection_with_default(
            self.collection,
            lambda: self._pick_default_collection() or "",
        )
        collection = self.collection
        if not collection:
            return []

        terms, fields = normalize_query_inputs(terms, fields)
        max_terms = self._http_settings.text_fallback_terms
        filter_body = build_text_filter(terms, fields, max_terms=max_terms, require_all=True)
        if not filter_body:
            return []
        body = build_vector_search_body(vector, top_k=top_k, filter_body=filter_body)
        r = self._request(
            build_search_request(
                collection,
                body,
                timeout=self._http_settings.text_timeout,
            )
        )
        if r.status_code == 404:
            return []
        r.raise_for_status()
        result = parse_search_result(r.json())
        if result:
            return result

        # Relax to "any term" if strict matching yields nothing.
        relax_body = build_vector_search_body(
            vector,
            top_k=top_k,
            filter_body=build_text_filter(terms, fields, max_terms=max_terms, require_all=False),
        )
        r2 = self._request(
            build_search_request(
                collection,
                relax_body,
                timeout=self._http_settings.text_timeout,
            )
        )
        if r2.status_code == 404:
            return []
        r2.raise_for_status()
        return parse_search_result(r2.json())

    def scroll_with_text(
        self,
        *,
        terms: List[str],
        fields: List[str],
        limit: int,
        require_all: bool = True,
        offset: Optional[str] = None,
    ) -> List[Dict[str, Any]]:
        terms, fields = normalize_query_inputs(terms, fields)
        if not terms or not fields:
            return []
        self.collection = resolve_collection_with_default(
            self.collection,
            lambda: self._pick_default_collection() or "",
        )
        collection = self.collection
        if not collection:
            return []

        max_terms = self._http_settings.text_fallback_terms
        filter_body = build_text_filter(terms, fields, max_terms=max_terms, require_all=require_all)
        if not filter_body:
            return []
        body = build_scroll_body(limit=limit, filter_body=filter_body, offset=offset)
        r = self._request(
            build_scroll_request(
                collection,
                body,
                timeout=self._http_settings.text_timeout,
            )
        )
        if r.status_code == 404:
            return []
        r.raise_for_status()
        points, _ = parse_scroll_points(r.json())
        return points

    def scroll_with_text_all(
        self,
        *,
        terms: List[str],
        fields: List[str],
        limit: int,
        require_all: bool = True,
    ) -> List[Dict[str, Any]]:
        terms, fields = normalize_query_inputs(terms, fields)
        if not terms or not fields:
            return []
        self.collection = resolve_collection_with_default(
            self.collection,
            lambda: self._pick_default_collection() or "",
        )
        collection = self.collection
        if not collection:
            return []

        max_terms = self._http_settings.text_fallback_terms
        filter_body = build_text_filter(terms, fields, max_terms=max_terms, require_all=require_all)
        if not filter_body:
            return []

        hard_cap = self._http_settings.text_scroll_hard_cap
        cap = int(limit) if limit is not None else 0
        if cap <= 0:
            cap = hard_cap
        else:
            cap = min(cap, hard_cap)
        batch_size = max(1, min(self._http_settings.text_scroll_batch, cap))

        collected: List[Dict[str, Any]] = []
        offset: Optional[str] = None
        while len(collected) < cap:
            body = build_scroll_body(
                limit=int(min(batch_size, cap - len(collected))),
                filter_body=filter_body,
                offset=offset,
            )
            r = self._request(
                build_scroll_request(
                    collection,
                    body,
                    timeout=self._http_settings.text_timeout,
                )
            )
            if r.status_code == 404:
                break
            r.raise_for_status()
            points, next_offset = parse_scroll_points(r.json())
            if not points:
                break
            collected.extend(points)
            if not next_offset:
                break
            offset = next_offset
        return collected

    def delete_by_filter(self, filter_body: Dict[str, Any]) -> Dict[str, Any]:
        """Delete points matching the supplied Qdrant filter."""
        r = self._request(
            build_delete_by_filter_request(
                self.collection,
                filter_body,
                timeout=self._http_settings.delete_timeout,
            )
        )
        r.raise_for_status()
        return r.json()

    def delete_by_doc_id(self, doc_id: str) -> Dict[str, Any]:
        """Delete all points that belong to the provided document id."""
        return self.delete_by_filter(build_delete_filter(doc_id))

    def get_collection_config(self) -> Dict[str, Any]:
        """Fetch the collection configuration from Qdrant."""
        r = self._request(build_get_collection_request(self.collection))
        r.raise_for_status()
        return parse_collection_config(r.json())

    def get_vector_size(self) -> Optional[int]:
        """Read the configured vector size; return None if it cannot be derived."""
        try:
            cfg = self.get_collection_config()
            return vector_size_from_config(cfg)
        except Exception:
            return None
