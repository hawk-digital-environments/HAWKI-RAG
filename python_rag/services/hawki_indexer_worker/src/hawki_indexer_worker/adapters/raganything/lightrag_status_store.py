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
import glob
import os
from typing import Any, TypeVar

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

from hawki_indexer_worker.adapters.raganything.doc_status import (
    annotate_duplicate_skip_metadata,
    chunk_item_dicts,
    count_status_records,
    is_duplicate_doc_record,
    merge_chunk_payloads,
    sort_chunk_files,
)

_RecordT = TypeVar("_RecordT")


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
        self._file_name = os.path.join(
            workspace_dir, f"{base_name}.json"
        )  # legacy single-file fallback
        self._chunk_prefix = os.path.join(workspace_dir, f"{base_name}_chunk_")
        self._data = None
        self._storage_lock = None
        self.storage_updated = None

    def _chunk_file_path(self, index: int) -> str:
        return f"{self._chunk_prefix}{index}.json"

    def _chunk_files(self) -> list[str]:
        pattern = f"{self._chunk_prefix}*.json"
        return sort_chunk_files(glob.glob(pattern))

    def _load_all_chunk_data(self) -> dict[str, Any]:
        chunk_files = self._chunk_files()
        if chunk_files:
            return merge_chunk_payloads(chunk_files, load_json)

        # Backward-compatible migration path: if the old monolithic file exists, load it.
        legacy = load_json(self._file_name) or {}
        return legacy if isinstance(legacy, dict) else {}

    @staticmethod
    def _is_duplicate_doc_record(doc_id: str, doc: object) -> bool:
        return is_duplicate_doc_record(
            doc_id,
            doc,
            failed_status_value=DocStatus.FAILED.value,
        )

    @classmethod
    def _annotate_duplicate_skip_metadata(cls, doc_id: str, doc: _RecordT) -> _RecordT:
        return annotate_duplicate_skip_metadata(
            doc_id,
            doc,
            failed_status_value=DocStatus.FAILED.value,
        )

    async def initialize(self):
        """Initialize storage data from chunked files (or legacy single file)."""
        self._storage_lock = get_namespace_lock(
            self.namespace, workspace=self.workspace
        )
        self.storage_updated = await get_update_flag(
            self.namespace, workspace=self.workspace
        )
        async with get_data_init_lock():
            need_init = await try_initialize_namespace(
                self.namespace, workspace=self.workspace
            )
            self._data = await get_namespace_data(
                self.namespace, workspace=self.workspace
            )
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
        counts = {status.value: 0 for status in DocStatus}
        counts[self.DUPLICATE_SKIPPED_COUNT_KEY] = 0
        if self._storage_lock is None:
            raise StorageNotInitializedError("ChunkedJsonDocStatusStorage")
        async with self._storage_lock:
            counts = count_status_records(
                self._data.items(),
                status_values=[status.value for status in DocStatus],
                failed_status_value=DocStatus.FAILED.value,
                duplicate_count_key=self.DUPLICATE_SKIPPED_COUNT_KEY,
            )
        return counts

    async def get_docs_by_status(
        self, status: DocStatus
    ) -> dict[str, DocProcessingStatus]:
        """
        Return docs for a status, but exclude duplicate-attempt records from FAILED.

        LightRAG writes duplicates as FAILED doc-status records. Treat them as skipped here so
        the processing pipeline does not repeatedly preserve/revisit them as actionable failures.
        """
        result: dict[str, DocProcessingStatus] = {}
        if self._storage_lock is None:
            raise StorageNotInitializedError("ChunkedJsonDocStatusStorage")
        async with self._storage_lock:
            for doc_id, doc_data in self._data.items():
                if not isinstance(doc_data, dict):
                    continue
                if doc_data.get("status") != status.value:
                    continue
                if status == DocStatus.FAILED and self._is_duplicate_doc_record(
                    str(doc_id), doc_data
                ):
                    continue
                try:
                    data = dict(doc_data)
                    data.pop("content", None)
                    if "file_path" not in data:
                        data["file_path"] = "no-file-path"
                    if "metadata" not in data or not isinstance(
                        data.get("metadata"), dict
                    ):
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
        if self._storage_lock is None:
            raise StorageNotInitializedError("ChunkedJsonDocStatusStorage")

        async with self._storage_lock:
            if not self.storage_updated.value:
                return

            data_dict = (
                dict(self._data)
                if hasattr(self._data, "_getvalue")
                else dict(self._data or {})
            )
            logger.debug(
                f"[{self.workspace}] Process {os.getpid()} doc status writing {len(data_dict)} records to {self.namespace} (chunked)"
            )

            items = list(data_dict.items())
            chunk_dicts = chunk_item_dicts(
                items, max_entries=self.MAX_ENTRIES_PER_CHUNK
            )

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
