"""
Chunked JSON DocStatus storage for LightRAG.

Why this exists:
- Neo4j is a graph store and not a good fit for LightRAG's document status KV records.
- LightRAG's default JsonDocStatusStorage writes one large JSON file
  (`kv_store_doc_status.json`), which can become unwieldy.

This drop-in replacement keeps the same record schema as JsonDocStatusStorage but writes
multiple chunk files:
    kv_store_doc_status_chunk_1.json
    kv_store_doc_status_chunk_2.json
    ...

Each chunk contains at most 2000 entries.
"""

from __future__ import annotations

from dataclasses import dataclass
import json
import glob
import os
import re
import logging
from typing import Any

try:
    from lightrag.base import DocProcessingStatus, DocStatus
    from lightrag.exceptions import StorageNotInitializedError
    from lightrag.kg.json_doc_status_impl import JsonDocStatusStorage
    from lightrag.kg.shared_storage import (
        clear_all_update_flags,
        get_data_init_lock,
        get_namespace_data,
        get_namespace_lock,
        get_update_flag,
        try_initialize_namespace,
    )
    from lightrag.utils import load_json, logger, write_json

    _LIGHTRAG_DOC_STATUS_AVAILABLE = True
    _LIGHTRAG_DOC_STATUS_ERROR: Exception | None = None
except Exception as exc:  # pragma: no cover - optional dependency path
    _LIGHTRAG_DOC_STATUS_AVAILABLE = False
    _LIGHTRAG_DOC_STATUS_ERROR = exc

    logger = logging.getLogger(__name__)

    class StorageNotInitializedError(RuntimeError):
        """Raised when optional LightRAG storage dependencies are not installed."""

    class DocProcessingStatus(dict):
        """Typed compatibility fallback when LightRAG is unavailable."""

    class _DocStatusValue:
        def __init__(self, value: str) -> None:
            self.value = value

    class DocStatus:
        FAILED = _DocStatusValue("FAILED")

        @classmethod
        def __iter__(cls):
            yield cls.FAILED

    class JsonDocStatusStorage:
        """Fallback base class for optional dependency environments."""

        def __init__(self, *args: Any, **kwargs: Any) -> None:
            self.global_config = args[0] if args else kwargs.get("global_config", {})
            self.namespace = kwargs.get("namespace", "") if len(args) < 2 else args[1]
            self.workspace = kwargs.get("workspace", None)
            self.storage_updated = kwargs.get("storage_updated", None)

        async def upsert(self, *_args: Any, **_kwargs: Any) -> None:
            raise StorageNotInitializedError(
                "LightRAG optional dependency 'lightrag' is unavailable for chunked doc status storage."
            )

    class _AsyncNoopContextManager:
        async def __aenter__(self) -> _AsyncNoopContextManager:
            return self

        async def __aexit__(
            self,
            exc_type: type[BaseException] | None,
            exc: BaseException | None,
            tb: object,
        ) -> None:
            return None

    class _UpdateFlag:
        value = False

    def get_data_init_lock() -> _AsyncNoopContextManager:
        return _AsyncNoopContextManager()

    def get_namespace_lock(*_args: Any, **_kwargs: Any) -> _AsyncNoopContextManager:
        return _AsyncNoopContextManager()

    async def get_namespace_data(*_args: Any, **_kwargs: Any) -> dict[str, Any]:
        return {}

    async def try_initialize_namespace(*_args: Any, **_kwargs: Any) -> bool:
        return False

    async def get_update_flag(*_args: Any, **_kwargs: Any) -> _UpdateFlag:
        return _UpdateFlag()

    async def clear_all_update_flags(*_args: Any, **_kwargs: Any) -> None:
        return None

    def _safe_load_json(path: str) -> Any:
        try:
            with open(path, "r", encoding="utf-8") as handle:
                return json.load(handle)
        except FileNotFoundError:
            return {}
        except Exception as exc:
            logger.debug("chunked doc status load_json failed for %s: %s", path, exc)
            return {}

    def _safe_write_json(payload: Any, path: str) -> bool:
        try:
            with open(path, "w", encoding="utf-8") as handle:
                json.dump(payload, handle, ensure_ascii=False)
            return True
        except Exception as exc:
            logger.warning("chunked doc status write_json failed for %s: %s", path, exc)
            return False

    load_json = _safe_load_json
    write_json = _safe_write_json


def _ensure_lightrag_doc_status_storage_available() -> None:
    if not _LIGHTRAG_DOC_STATUS_AVAILABLE:
        raise StorageNotInitializedError(
            "LightRAG optional dependency 'lightrag' is unavailable for ChunkedJsonDocStatusStorage"
            + (f" (details: {_LIGHTRAG_DOC_STATUS_ERROR})" if _LIGHTRAG_DOC_STATUS_ERROR else "")
        )


@dataclass
class ChunkedJsonDocStatusStorage(JsonDocStatusStorage):
    """JsonDocStatusStorage variant that persists data in chunked JSON files."""

    MAX_ENTRIES_PER_CHUNK = 2000
    DUPLICATE_SKIPPED_COUNT_KEY = "skipped_duplicates"

    def __post_init__(self):
        working_dir = self.global_config["working_dir"]
        if self.workspace:
            workspace_dir = os.path.join(working_dir, self.workspace)
        else:
            workspace_dir = working_dir
            self.workspace = ""

        os.makedirs(workspace_dir, exist_ok=True)
        base_name = f"kv_store_{self.namespace}"
        self._file_name = os.path.join(workspace_dir, f"{base_name}.json")  # legacy single-file fallback
        self._chunk_prefix = os.path.join(workspace_dir, f"{base_name}_chunk_")
        self._data = None
        self._storage_lock = None
        self.storage_updated = None

    def _chunk_file_path(self, index: int) -> str:
        return f"{self._chunk_prefix}{index}.json"

    def _chunk_files(self) -> list[str]:
        pattern = f"{self._chunk_prefix}*.json"
        paths = glob.glob(pattern)

        def _idx(path: str) -> int:
            m = re.search(r"_chunk_(\d+)\.json$", path)
            return int(m.group(1)) if m else 10**9

        return sorted(paths, key=_idx)

    def _load_all_chunk_data(self) -> dict[str, Any]:
        merged: dict[str, Any] = {}
        chunk_files = self._chunk_files()
        if chunk_files:
            for path in chunk_files:
                payload = load_json(path) or {}
                if isinstance(payload, dict):
                    merged.update(payload)
            return merged

        # Backward-compatible migration path: if the old monolithic file exists, load it.
        legacy = load_json(self._file_name) or {}
        return legacy if isinstance(legacy, dict) else {}

    @staticmethod
    def _is_duplicate_doc_record(doc_id: str, doc: Any) -> bool:
        if not isinstance(doc, dict):
            return False
        metadata = doc.get("metadata")
        if isinstance(metadata, dict) and metadata.get("is_duplicate") is True:
            return True
        if isinstance(doc_id, str) and doc_id.startswith("dup-"):
            return True
        if str(doc.get("status") or "") != DocStatus.FAILED.value:
            return False
        error_msg = str(doc.get("error_msg") or "")
        return "Content already exists." in error_msg and "Original doc_id:" in error_msg

    @classmethod
    def _annotate_duplicate_skip_metadata(cls, doc_id: str, doc: Any) -> Any:
        if not cls._is_duplicate_doc_record(doc_id, doc):
            return doc
        if not isinstance(doc, dict):
            return doc
        metadata = doc.get("metadata")
        if not isinstance(metadata, dict):
            metadata = {}
        # LightRAG uses `FAILED` for duplicate attempts. We preserve the raw status for
        # compatibility, but mark the record so workflow queries can treat it as skipped.
        metadata.setdefault("is_duplicate", True)
        metadata.setdefault("effective_status", "skipped")
        metadata.setdefault("skip_reason", "duplicate")
        doc["metadata"] = metadata
        return doc

    async def initialize(self):
        """Initialize storage data from chunked files (or legacy single file)."""
        _ensure_lightrag_doc_status_storage_available()
        self._storage_lock = get_namespace_lock(self.namespace, workspace=self.workspace)
        self.storage_updated = await get_update_flag(self.namespace, workspace=self.workspace)
        async with get_data_init_lock():
            need_init = await try_initialize_namespace(self.namespace, workspace=self.workspace)
            self._data = await get_namespace_data(self.namespace, workspace=self.workspace)
            if need_init:
                loaded_data = self._load_all_chunk_data()
                async with self._storage_lock:
                    for doc_id, doc in loaded_data.items():
                        self._annotate_duplicate_skip_metadata(str(doc_id), doc)
                    self._data.update(loaded_data)
                    logger.info(
                        f"[{self.workspace}] Process {os.getpid()} doc status load {self.namespace} with {len(loaded_data)} records (chunked)"
                    )

    async def upsert(self, data: dict[str, dict[str, Any]]) -> None:
        _ensure_lightrag_doc_status_storage_available()
        if not data:
            return
        normalized: dict[str, dict[str, Any]] = {}
        dup_count = 0
        for doc_id, doc_data in data.items():
            rec = dict(doc_data or {})
            before_dup = self._is_duplicate_doc_record(doc_id, rec)
            self._annotate_duplicate_skip_metadata(doc_id, rec)
            if before_dup:
                dup_count += 1
            normalized[doc_id] = rec
        if dup_count:
            logger.debug(
                f"[{self.workspace}] Marked {dup_count} duplicate doc-status record(s) as effective skipped"
            )
        await super().upsert(normalized)

    async def get_status_counts(self) -> dict[str, int]:
        """Get counts of documents in each status, excluding duplicate attempts from FAILED."""
        _ensure_lightrag_doc_status_storage_available()
        counts = {status.value: 0 for status in DocStatus}
        counts[self.DUPLICATE_SKIPPED_COUNT_KEY] = 0
        if self._storage_lock is None:
            raise StorageNotInitializedError("ChunkedJsonDocStatusStorage")
        async with self._storage_lock:
            for doc_id, doc in self._data.items():
                if self._is_duplicate_doc_record(str(doc_id), doc):
                    counts[self.DUPLICATE_SKIPPED_COUNT_KEY] += 1
                    continue
                status_val = str((doc or {}).get("status") or "")
                if status_val in counts:
                    counts[status_val] += 1
                elif status_val:
                    counts[status_val] = counts.get(status_val, 0) + 1
        return counts

    async def get_docs_by_status(
        self, status: DocStatus
    ) -> dict[str, DocProcessingStatus]:
        """
        Return docs for a status, but exclude duplicate-attempt records from FAILED.

        LightRAG writes duplicates as FAILED doc-status records. Treat them as skipped here so
        the processing pipeline does not repeatedly preserve/revisit them as actionable failures.
        """
        _ensure_lightrag_doc_status_storage_available()
        result: dict[str, DocProcessingStatus] = {}
        if self._storage_lock is None:
            raise StorageNotInitializedError("ChunkedJsonDocStatusStorage")
        async with self._storage_lock:
            for doc_id, doc_data in self._data.items():
                if not isinstance(doc_data, dict):
                    continue
                if doc_data.get("status") != status.value:
                    continue
                if status == DocStatus.FAILED and self._is_duplicate_doc_record(str(doc_id), doc_data):
                    continue
                try:
                    data = dict(doc_data)
                    data.pop("content", None)
                    if "file_path" not in data:
                        data["file_path"] = "no-file-path"
                    if "metadata" not in data or not isinstance(data.get("metadata"), dict):
                        data["metadata"] = {}
                    else:
                        self._annotate_duplicate_skip_metadata(str(doc_id), data)
                    if "error_msg" not in data:
                        data["error_msg"] = None
                    result[str(doc_id)] = DocProcessingStatus(**data)
                except KeyError as exc:
                    logger.error(
                        f"[{self.workspace}] Missing required field for document {doc_id}: {exc}"
                    )
                    continue
        return result

    async def index_done_callback(self) -> None:
        _ensure_lightrag_doc_status_storage_available()
        if self._storage_lock is None:
            raise StorageNotInitializedError("ChunkedJsonDocStatusStorage")

        async with self._storage_lock:
            if not self.storage_updated.value:
                return

            data_dict = dict(self._data) if hasattr(self._data, "_getvalue") else dict(self._data or {})
            logger.debug(
                f"[{self.workspace}] Process {os.getpid()} doc status writing {len(data_dict)} records to {self.namespace} (chunked)"
            )

            items = list(data_dict.items())
            if not items:
                chunk_dicts = [{}]
            else:
                chunk_dicts = []
                for start in range(0, len(items), self.MAX_ENTRIES_PER_CHUNK):
                    chunk_dicts.append(dict(items[start : start + self.MAX_ENTRIES_PER_CHUNK]))

            existing_chunk_files = set(self._chunk_files())
            written_files: list[str] = []
            needs_reload = False

            for idx, chunk_payload in enumerate(chunk_dicts, start=1):
                path = self._chunk_file_path(idx)
                written_files.append(path)
                if write_json(chunk_payload, path):
                    needs_reload = True

            # Remove stale chunk files from older larger snapshots.
            for stale_path in existing_chunk_files - set(written_files):
                try:
                    os.remove(stale_path)
                except FileNotFoundError:
                    pass
                except Exception as exc:
                    logger.warning(
                        f"[{self.workspace}] Failed to remove stale doc status chunk {stale_path}: {exc}"
                    )

            # Remove legacy monolithic file if present to avoid confusion.
            if os.path.exists(self._file_name):
                try:
                    os.remove(self._file_name)
                except Exception:
                    # Non-fatal: chunked files are authoritative.
                    pass

            # If any chunk writer sanitized JSON (e.g. NaN cleanup), reload merged data into shared memory.
            if needs_reload:
                logger.info(
                    f"[{self.workspace}] Reloading sanitized chunked data into shared memory for {self.namespace}"
                )
                cleaned_data = self._load_all_chunk_data()
                self._data.clear()
                self._data.update(cleaned_data)

            await clear_all_update_flags(self.namespace, workspace=self.workspace)
