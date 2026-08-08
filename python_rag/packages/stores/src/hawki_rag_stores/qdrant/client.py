from __future__ import annotations

import logging
from typing import Any

from hawki_rag_stores.qdrant.collections import (
    pick_most_populated_collection,
    vector_size_from_config,
)
from hawki_rag_stores.qdrant.payloads import (
    build_delete_filter,
    build_match_filter,
    build_scroll_body,
    build_search_body,
    build_text_filter,
    build_vector_search_body,
    combine_filter_bodies,
    iter_batches,
)
from hawki_rag_stores.qdrant.settings import (
    QdrantHTTPSettings,
    QdrantSettings,
    qdrant_http_settings_from_env,
    qdrant_settings_from_env,
)
from hawki_rag_stores.qdrant.gateway import QdrantHTTPGateway
from hawki_rag_stores.qdrant._client_policy import (
    callable_supports_kwarg,
    gateway_supports_operation_id,
    request_exception_type,
    resolve_per_collection_limit,
    resolve_selected_collection,
)
from hawki_rag_stores.qdrant.responses import (
    parse_collection_config,
    parse_collection_names,
    parse_count,
)
from hawki_rag_stores.qdrant.search import (
    normalize_query_inputs,
    search_with_fallback_collections,
)
from hawki_rag_stores.qdrant.interpretation import (
    attach_collection,
    parse_search_payload,
    parse_scroll_payload,
    sort_hits_by_score,
)
from hawki_rag_stores.qdrant.transport import QdrantHTTPTransport
from hawki_rag_resilience.optional_imports import import_required_module

logger = logging.getLogger(__name__)


class ScopedCollectionNotReadyError(RuntimeError):
    """The authorized query collection does not exist in Qdrant."""


class _RequestsProxy:
    """Patchable proxy that lazily loads requests for session construction."""

    def Session(self) -> Any:
        return import_required_module(
            "requests",
            install_hint="Install hawki-rag-stores to use Qdrant HTTP transport.",
        ).Session()


requests = _RequestsProxy()


class QdrantHTTP:
    """Lightweight client used by the FastAPI bridge to talk to Qdrant."""

    def __init__(
        self,
        settings: QdrantSettings | None = None,
        http_settings: QdrantHTTPSettings | None = None,
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
            operation_attempts=qdrant_settings.retry_attempts_by_operation,
            log_latency=self._http_settings.log_latency,
            session=requests.Session(),
        )
        self._gateway = QdrantHTTPGateway(
            transport=self._transport,
            collection=self.collection,
        )
        self._scoped_collection: str | None = None

    def set_collection(self, collection: str) -> None:
        """Switch the active collection for all collection-scoped requests."""
        selected = str(collection or "").strip()
        if not selected:
            return
        if self._scoped_collection is not None and selected != self._scoped_collection:
            raise RuntimeError("Cannot replace an authorized query collection.")
        self.collection = selected
        self._gateway.collection = selected

    def select_scoped_collection(self, collection: str) -> None:
        """Lock this request-local client to one authorized collection."""

        selected = str(collection or "").strip()
        if not selected:
            raise ValueError("An authorized Qdrant collection is required.")
        if self._scoped_collection is not None and selected != self._scoped_collection:
            raise RuntimeError("Cannot replace an authorized query collection.")
        self.collection = selected
        self._gateway.collection = selected
        self._scoped_collection = selected

    def _raise_if_scoped_collection_missing(self, response: Any) -> None:
        if self._scoped_collection is not None and response.status_code == 404:
            raise ScopedCollectionNotReadyError(
                "Authorized dataset storage is not ready."
            )

    def ensure_collection(self, vector_size: int, distance: str = "Cosine") -> None:
        """Create the collection if it does not already exist."""
        r = self._gateway.get_collection()
        if r.status_code == 200:
            return
        rc = self._gateway.ensure_collection(vector_size=vector_size, distance=distance)
        rc.raise_for_status()

    def list_collections(self) -> list[str]:
        """Return all collection names available in Qdrant."""
        r = self._gateway.list_collections()
        r.raise_for_status()
        return parse_collection_names(r.json())

    def _pick_default_collection(self) -> str | None:
        """Pick the most populated collection when no default is configured."""
        try:
            names = self.list_collections()
        except Exception as exc:
            logger.warning(
                "Qdrant default selection failed to list collections: %s", exc
            )
            return None
        return pick_most_populated_collection(
            (name, self.count_points(name)) for name in names
        )

    def _search_collection(
        self,
        collection: str,
        body: dict[str, Any],
        timeout: float,
    ) -> list[dict[str, Any]]:
        response = self._gateway.search(collection, body, timeout=timeout)
        return parse_search_payload(response, empty_on_not_found=True)

    def upsert(
        self,
        points: list[dict[str, Any]],
        *,
        idempotency_key: str | None = None,
    ) -> None:
        """Upsert batches of points into the chosen collection."""
        if not points:
            return
        if gateway_supports_operation_id(self._gateway, "upsert"):
            r = self._gateway.upsert(
                points,
                timeout=self._http_settings.upsert_timeout,
                operation_id=idempotency_key,
            )
        else:
            r = self._gateway.upsert(
                points,
                timeout=self._http_settings.upsert_timeout,
            )
        if r.status_code >= 400:
            logger.error(
                "Qdrant upsert failed status=%s body=%s", r.status_code, r.text
            )
        r.raise_for_status()

    def upsert_points(
        self,
        points: list[dict[str, Any]],
        *,
        batch_size: int = 64,
        idempotency_key: str | None = None,
    ) -> None:
        """Upsert points in batches."""
        if not points:
            return
        size = max(1, int(batch_size))
        logger.info("qdrant:upsert_points total=%s batch_size=%s", len(points), size)
        for batch in iter_batches(points, size):
            self.upsert(batch, idempotency_key=idempotency_key)

    def count_points(
        self,
        collection: str | None = None,
        exact: bool = True,
        filter_body: dict[str, Any] | None = None,
    ) -> int | None:
        """Return the number of points stored in a collection."""
        col = collection or self.collection
        try:
            count_kwargs: dict[str, Any] = {
                "exact": exact,
                "timeout": self._http_settings.count_timeout,
            }
            if filter_body and callable_supports_kwarg(
                self._gateway, "count_points", "filter_body"
            ):
                count_kwargs["filter_body"] = filter_body
            r = self._gateway.count_points(col, **count_kwargs)
            r.raise_for_status()
            return parse_count(r.json())
        except Exception as exc:
            requests_error = request_exception_type()
            if not isinstance(exc, requests_error):
                raise
            status = getattr(getattr(exc, "response", None), "status_code", None)
            if status == 404:
                return None
            logger.warning("Qdrant count failed for collection %s: %s", col, exc)
            return None

    def count_points_by_doc_id(
        self,
        doc_id: str,
        *,
        collection: str | None = None,
        exact: bool = True,
    ) -> int | None:
        """Return the number of points that belong to one document id."""
        return self.count_points(
            collection=collection,
            exact=exact,
            filter_body=build_delete_filter(doc_id),
        )

    def search(
        self,
        vector: list[float],
        top_k: int = 5,
        filters: dict[str, Any] | None = None,
        *,
        score_threshold: float | None = None,
        params: dict[str, Any] | None = None,
        with_payload: bool = True,
        with_vector: bool = False,
        payload_projection: list[str] | None = None,
        keyword_terms: list[str] | None = None,
        keyword_fields: list[str] | None = None,
    ) -> list[dict[str, Any]]:
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

        if self._scoped_collection is not None:
            r = self._gateway.search(self._scoped_collection, body, timeout=timeout)
            self._raise_if_scoped_collection_missing(r)
            return parse_search_payload(r)

        if self._http_settings.search_all:
            try:
                collections = self.list_collections()
            except Exception as exc:
                logger.warning("Qdrant search-all failed to list collections: %s", exc)
                collections = []
            if not collections:
                return []
            max_per_collection = resolve_per_collection_limit(
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

        self.collection = resolve_selected_collection(
            self.collection,
            lambda: self._pick_default_collection() or "",
        )
        collection = self.collection

        r = self._gateway.search(collection, body, timeout=timeout)
        if r.status_code != 404:
            return parse_search_payload(r)

        fallback_enabled = self._http_settings.fallback_all
        if not fallback_enabled:
            r.raise_for_status()
            return []

        try:
            collections = self.list_collections()
        except Exception as exc:
            logger.warning(
                "Qdrant collection fallback failed to list collections: %s", exc
            )
            r.raise_for_status()
            return []

        max_per_collection = resolve_per_collection_limit(
            self._http_settings.fallback_per_collection,
            top_k,
        )
        merged: list[dict[str, Any]] = []
        for name in collections:
            body["limit"] = int(max_per_collection)
            results = self._search_collection(name, body, timeout)
            merged.extend(attach_collection(results, name))

        return sort_hits_by_score(merged, limit=top_k)

    def search_with_text(
        self,
        vector: list[float],
        *,
        top_k: int,
        terms: list[str],
        fields: list[str],
        filters: dict[str, Any] | None = None,
    ) -> list[dict[str, Any]]:
        terms = [t for t in (terms or []) if t]
        fields = [f for f in (fields or []) if f]
        if not terms or not fields:
            return []
        self.collection = resolve_selected_collection(
            self.collection,
            lambda: self._pick_default_collection() or "",
        )
        collection = self.collection
        if not collection:
            return []

        terms, fields = normalize_query_inputs(terms, fields)
        max_terms = self._http_settings.text_fallback_terms
        filter_body = combine_filter_bodies(
            build_match_filter(filters),
            build_text_filter(terms, fields, max_terms=max_terms, require_all=True),
        )
        if not filter_body:
            return []
        body = build_vector_search_body(vector, top_k=top_k, filter_body=filter_body)
        r = self._gateway.search(
            collection, body, timeout=self._http_settings.text_timeout
        )
        self._raise_if_scoped_collection_missing(r)
        result = parse_search_payload(r, empty_on_not_found=True)
        if result:
            return result

        # Relax to "any term" if strict matching yields nothing.
        relax_body = build_vector_search_body(
            vector,
            top_k=top_k,
            filter_body=combine_filter_bodies(
                build_match_filter(filters),
                build_text_filter(
                    terms, fields, max_terms=max_terms, require_all=False
                ),
            ),
        )
        r2 = self._gateway.search(
            collection,
            relax_body,
            timeout=self._http_settings.text_timeout,
        )
        self._raise_if_scoped_collection_missing(r2)
        return parse_search_payload(r2, empty_on_not_found=True)

    def scroll_with_text(
        self,
        *,
        terms: list[str],
        fields: list[str],
        limit: int,
        require_all: bool = True,
        offset: str | None = None,
        filters: dict[str, Any] | None = None,
    ) -> list[dict[str, Any]]:
        terms, fields = normalize_query_inputs(terms, fields)
        if not terms or not fields:
            return []
        self.collection = resolve_selected_collection(
            self.collection,
            lambda: self._pick_default_collection() or "",
        )
        collection = self.collection
        if not collection:
            return []

        max_terms = self._http_settings.text_fallback_terms
        filter_body = combine_filter_bodies(
            build_match_filter(filters),
            build_text_filter(
                terms, fields, max_terms=max_terms, require_all=require_all
            ),
        )
        if not filter_body:
            return []
        body = build_scroll_body(limit=limit, filter_body=filter_body, offset=offset)
        r = self._gateway.scroll(
            collection, body, timeout=self._http_settings.text_timeout
        )
        self._raise_if_scoped_collection_missing(r)
        if r.status_code == 404:
            return []
        points, _ = parse_scroll_payload(r, empty_on_not_found=True)
        return points

    def scroll_with_text_all(
        self,
        *,
        terms: list[str],
        fields: list[str],
        limit: int,
        require_all: bool = True,
        filters: dict[str, Any] | None = None,
    ) -> list[dict[str, Any]]:
        terms, fields = normalize_query_inputs(terms, fields)
        if not terms or not fields:
            return []
        self.collection = resolve_selected_collection(
            self.collection,
            lambda: self._pick_default_collection() or "",
        )
        collection = self.collection
        if not collection:
            return []

        max_terms = self._http_settings.text_fallback_terms
        filter_body = combine_filter_bodies(
            build_match_filter(filters),
            build_text_filter(
                terms, fields, max_terms=max_terms, require_all=require_all
            ),
        )
        if not filter_body:
            return []

        hard_cap = self._http_settings.text_scroll_hard_cap
        cap = int(limit) if limit is not None else 0
        if cap <= 0:
            cap = hard_cap
        else:
            cap = min(cap, hard_cap)
        batch_size = max(1, min(self._http_settings.text_scroll_batch, cap))

        collected: list[dict[str, Any]] = []
        offset: str | None = None
        while len(collected) < cap:
            body = build_scroll_body(
                limit=int(min(batch_size, cap - len(collected))),
                filter_body=filter_body,
                offset=offset,
            )
            r = self._gateway.scroll(
                collection,
                body,
                timeout=self._http_settings.text_timeout,
            )
            self._raise_if_scoped_collection_missing(r)
            if r.status_code == 404:
                break
            points, next_offset = parse_scroll_payload(r)
            if not points:
                break
            collected.extend(points)
            if not next_offset:
                break
            offset = next_offset
        return collected

    def scroll_by_filter(
        self,
        filter_body: dict[str, Any],
        *,
        limit: int,
        offset: str | None = None,
    ) -> list[dict[str, Any]]:
        """Return points matching an exact Qdrant filter."""
        if not filter_body:
            return []
        self.collection = resolve_selected_collection(
            self.collection,
            lambda: self._pick_default_collection() or "",
        )
        collection = self.collection
        if not collection:
            return []
        body = build_scroll_body(limit=limit, filter_body=filter_body, offset=offset)
        r = self._gateway.scroll(
            collection, body, timeout=self._http_settings.text_timeout
        )
        self._raise_if_scoped_collection_missing(r)
        if r.status_code == 404:
            return []
        points, _ = parse_scroll_payload(r, empty_on_not_found=True)
        return points

    def find_points_by_payload(
        self, filters: dict[str, Any], *, limit: int = 1
    ) -> list[dict[str, Any]]:
        """Return points whose payload matches all provided key/value filters."""
        filter_body = build_match_filter(filters)
        if not filter_body:
            return []
        return self.scroll_by_filter(filter_body, limit=max(1, int(limit)))

    def delete_by_filter(
        self, filter_body: dict[str, Any], *, idempotency_key: str | None = None
    ) -> dict[str, Any]:
        """Delete points matching the supplied Qdrant filter."""
        if gateway_supports_operation_id(self._gateway, "delete_by_filter"):
            r = self._gateway.delete_by_filter(
                filter_body,
                timeout=self._http_settings.delete_timeout,
                operation_id=idempotency_key,
            )
        else:
            r = self._gateway.delete_by_filter(
                filter_body,
                timeout=self._http_settings.delete_timeout,
            )
        if r.status_code == 404:
            return {"result": {"status": "not_found", "deleted": 0}}
        if r.status_code == 400:
            # Invalid requests should fail fast so callers can route to a clear error boundary.
            raise RuntimeError(f"Qdrant delete request rejected: {r.text}")
        r.raise_for_status()
        return r.json()

    def delete_by_doc_id(
        self, doc_id: str, *, idempotency_key: str | None = None
    ) -> dict[str, Any]:
        """Delete all points that belong to the provided document id."""
        return self.delete_by_filter(
            build_delete_filter(doc_id), idempotency_key=idempotency_key
        )

    def get_collection_config(self) -> dict[str, Any]:
        """Fetch the collection configuration from Qdrant."""
        r = self._gateway.get_collection()
        r.raise_for_status()
        return parse_collection_config(r.json())

    def get_vector_size(self) -> int | None:
        """Read the configured vector size; return None if it cannot be derived."""
        try:
            cfg = self.get_collection_config()
            return vector_size_from_config(cfg)
        except Exception:
            return None
