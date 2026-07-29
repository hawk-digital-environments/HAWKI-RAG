"""Graph extraction orchestration for RAG-Anything."""

from __future__ import annotations

import logging
import threading
from pathlib import Path
from typing import Any, Dict, List, Optional

from infrastructure.raganything.cache import clear_graph_cache_files
from infrastructure.raganything.edge_parser import (
    triplets_from_raganything_edges as _triplets_from_raganything_edges_raw,
)
from infrastructure.raganything.fallback_parser import parse_raganything_llm_cache, relation_label_from_text
from infrastructure.raganything.raganything_utils import (
    is_junk_graph_label,
    normalize_graph_embed_text,
)
from infrastructure.raganything.raganything_settings import RagAnythingGraphSettings, load_raganything_graph_settings
from infrastructure.raganything.text import clean_graph_text
from infrastructure.raganything.raganything_client_config import (
    build_raganything_client,
    graph_runtime_cache_key,
)
from infrastructure.raganything.raganything_extract import (
    extract_triplets_from_graph_client,
    graph_content_list_from_input,
    raganything_extraction_doc_id,
    raganything_file_ref,
    stable_raganything_doc_id,
)
from infrastructure.raganything.raganything_cache import scrub_raganything_kv_graph_junk as _scrub_raganything_kv_graph_junk
from infrastructure.raganything.raganything_loop import RagAnythingGraphLoop
from infrastructure.raganything.raganything_summary import build_graph_runtime_summary
from infrastructure.raganything.provider_config import graph_model_override, provider_fingerprint

logger = logging.getLogger(__name__)


def _is_junk_graph_label(
    value: object,
    *,
    allowlist_raw: str | None = None,
    denylist_raw: str | None = None,
    strict: bool | None = None,
) -> bool:
    text = normalize_graph_embed_text(value)
    return is_junk_graph_label(
        text,
        allowlist_raw=allowlist_raw,
        denylist_raw=denylist_raw,
        strict=strict,
    )


class RagAnythingGraphService:
    """Owns graph extraction state and RAG-Anything lifecycle."""

    def __init__(
        self,
        working_dir: Path,
        logger_obj: logging.Logger | None = None,
        *,
        settings: RagAnythingGraphSettings | None = None,
    ) -> None:
        self.working_dir = Path(working_dir).expanduser()
        self.working_dir.mkdir(parents=True, exist_ok=True)
        self.logger = logger_obj or logger
        self._settings = settings or load_raganything_graph_settings()
        self._rag_graph_lock = threading.RLock()
        self._rag_graph_cache_key: str | None = None
        self.client: Any | None = None
        self._rag_graph_loop = RagAnythingGraphLoop(logger_obj=logger_obj)
        self._rag_graph_runtime_meta: dict[str, Any] = {
            "doc_status_storage": "JsonDocStatusStorage",
            "graph_storage": "NetworkXStorage(default)",
            "graph_client_initialized": False,
        }
        self._rag_graph_kv_junk_scrub_once_done = False

    @staticmethod
    def _graph_model_override(provider: object) -> str | None:
        return graph_model_override(provider)

    @staticmethod
    def _provider_fingerprint(provider: object) -> str:
        return provider_fingerprint(provider)

    def graph_cache_fingerprint(self, provider: object, *, neo4j_database: str | None = None) -> str:
        return graph_runtime_cache_key(
            working_dir=self.working_dir,
            provider=provider,
            settings=self._settings,
            neo4j_database=neo4j_database,
        )

    def _is_junk_graph_label(self, value: object) -> bool:
        return _is_junk_graph_label(
            value,
            allowlist_raw=self._settings.graph_embed_junk_allowlist,
            denylist_raw=self._settings.graph_embed_junk_denylist,
            strict=self._settings.graph_embed_junk_strict,
        )

    def _run_coro_sync(self, coro: Any) -> Any:
        return self._rag_graph_loop.run_sync(coro)

    def _close_raganything_instance(self, client: object | None) -> None:
        self._rag_graph_loop.close_raganything_instance(client)

    def clear_graph_cache(self) -> dict[str, Any]:
        with self._rag_graph_lock:
            self._close_raganything_instance(self.client)
            self.client = None
            self._rag_graph_cache_key = None
            self._rag_graph_kv_junk_scrub_once_done = False
            return clear_graph_cache_files(self.working_dir)

    def scrub_raganything_kv_graph_junk(
        self,
        *,
        rag_doc_id: str | None = None,
        full_scan: bool = False,
    ) -> dict[str, int]:
        return _scrub_raganything_kv_graph_junk(
            working_dir=self.working_dir,
            is_junk_graph_label=self._is_junk_graph_label,
            rag_doc_id=rag_doc_id,
            full_scan=full_scan,
            logger_obj=self.logger,
        )

    def graph_runtime_summary(self) -> dict[str, Any]:
        with self._rag_graph_lock:
            meta = dict(self._rag_graph_runtime_meta)
            initialized = bool(self.client is not None)
        return build_graph_runtime_summary(
            working_dir=self.working_dir,
            settings=self._settings,
            runtime_meta=meta,
            graph_client_initialized=initialized,
        )

    @staticmethod
    def graph_content_list_from_input(
        text: str,
        chunks: list[str] | None,
        *,
        image_paths: list[str] | None = None,
    ) -> list[dict[str, Any]]:
        return graph_content_list_from_input(text, chunks, image_paths=image_paths)

    @staticmethod
    def stable_raganything_doc_id(doc_id: str | None, file_path: str | None, content_list: list[dict[str, Any]]) -> str:
        return stable_raganything_doc_id(doc_id, file_path, content_list)

    def raganything_extraction_doc_id(
        self, doc_id: str | None, file_path: str | None, content_list: list[dict[str, Any]]
    ) -> str:
        return raganything_extraction_doc_id(doc_id, file_path, content_list)

    @staticmethod
    def raganything_file_ref(doc_id: str | None, file_path: str | None) -> str:
        return raganything_file_ref(doc_id, file_path)

    @staticmethod
    def relation_label_from_text(raw: str) -> str:
        return relation_label_from_text(raw)

    def triplets_from_edges(self, *, edges: list[dict[str, Any]], file_ref: str, created_at_floor: int) -> list[tuple[str, str, str]]:
        return _triplets_from_raganything_edges_raw(
            edges=edges, file_ref=file_ref, created_at_floor=created_at_floor, graph_debug=False
        )

    def triplets_from_llm_cache(self) -> list[tuple[str, str, str]]:
        cache_path = self.working_dir / "kv_store_llm_response_cache.json"
        return parse_raganything_llm_cache(cache_path)

    def _init_client(self, provider: Any, *, neo4j_database: str | None = None) -> Optional[Any]:
        client, runtime_meta, _ = build_raganything_client(
            working_dir=self.working_dir,
            provider=provider,
            settings=self._settings,
            logger_obj=self.logger,
            neo4j_database=neo4j_database,
        )
        if runtime_meta:
            self._rag_graph_runtime_meta = runtime_meta
        return client

    def get_or_create_client(self, provider: Any, *, neo4j_database: str | None = None) -> Optional[Any]:
        cache_key = self.graph_cache_fingerprint(provider, neo4j_database=neo4j_database)
        with self._rag_graph_lock:
            if self.client is not None and self._rag_graph_cache_key == cache_key:
                return self.client

            if self.client is not None and self._rag_graph_cache_key != cache_key:
                self._close_raganything_instance(self.client)
                self.client = None
                self._rag_graph_cache_key = None

            client = self._init_client(provider, neo4j_database=neo4j_database)
            self.client = client
            self._rag_graph_cache_key = cache_key if client is not None else None
            if client is not None and not self._rag_graph_kv_junk_scrub_once_done:
                try:
                    self.scrub_raganything_kv_graph_junk(full_scan=True)
                finally:
                    self._rag_graph_kv_junk_scrub_once_done = True
            return self.client

    def extract_triplets(
        self,
        text: str,
        *,
        provider: Any,
        chunks: list[str] | None,
        doc_id: str | None,
        file_path: str | None,
        image_paths: list[str] | None = None,
        neo4j_database: str | None = None,
        request_id: str | None = None,
    ) -> list[tuple[str, str, str]]:
        working_text = text
        cleaned_text = clean_graph_text(text)
        if not cleaned_text:
            working_text = text

        cleaned_chunks = None
        if chunks is not None:
            cleaned_chunks = []
            for ch in chunks:
                cleaned = clean_graph_text(ch)
                cleaned_chunks.append(cleaned if cleaned.strip() else ch)

        content_list = self.graph_content_list_from_input(
            cleaned_text if cleaned_text.strip() else working_text,
            cleaned_chunks if cleaned_chunks is not None else chunks,
            image_paths=image_paths,
        )
        if not content_list:
            logger.info("graph:extract_triplets skipping empty/tiny content doc_id=%s", doc_id or "-")
            return []

        if self._settings.graph_reset_cache_per_doc:
            cleared = self.clear_graph_cache()
            if cleared.get("failed"):
                logger.warning("RAG-Anything graph cache reset had failures: %s", cleared.get("failed"))
            else:
                logger.info(
                    "RAG-Anything graph cache reset before doc_id=%s removed=%s",
                    doc_id or "-",
                    len(cleared.get("removed") or []),
                )

        client = self.get_or_create_client(provider, neo4j_database=neo4j_database)
        if client is None:
            logger.warning("RAG-Anything graph client is not initialized; returning 0 triplets.")
            return []

        file_ref = self.raganything_file_ref(doc_id, file_path)
        with self._rag_graph_lock:
            return self._run_coro_sync(
                extract_triplets_from_graph_client(
                    client=client,
                    content_list=content_list,
                    doc_id=doc_id,
                    file_path=file_path,
                    file_ref=file_ref,
                    working_dir=self.working_dir,
                    settings=self._settings,
                    debug=self._settings.graph_debug,
                    logger_obj=self.logger,
                    neo4j_database=neo4j_database,
                    neo4j_uri=self._settings.neo4j_uri,
                    neo4j_user=self._settings.neo4j_user,
                    neo4j_password=self._settings.neo4j_password,
                    request_id=request_id,
                    scrub_raganything_kv_graph_junk=self.scrub_raganything_kv_graph_junk,
                )
            )
