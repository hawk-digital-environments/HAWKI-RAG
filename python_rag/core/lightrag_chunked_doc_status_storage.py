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
import re
from typing import Any

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


@dataclass
class ChunkedJsonDocStatusStorage(JsonDocStatusStorage):
    """JsonDocStatusStorage variant that persists data in chunked JSON files."""

    MAX_ENTRIES_PER_CHUNK = 2000

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

    async def initialize(self):
        """Initialize storage data from chunked files (or legacy single file)."""
        self._storage_lock = get_namespace_lock(self.namespace, workspace=self.workspace)
        self.storage_updated = await get_update_flag(self.namespace, workspace=self.workspace)
        async with get_data_init_lock():
            need_init = await try_initialize_namespace(self.namespace, workspace=self.workspace)
            self._data = await get_namespace_data(self.namespace, workspace=self.workspace)
            if need_init:
                loaded_data = self._load_all_chunk_data()
                async with self._storage_lock:
                    self._data.update(loaded_data)
                    logger.info(
                        f"[{self.workspace}] Process {os.getpid()} doc status load {self.namespace} with {len(loaded_data)} records (chunked)"
                    )

    async def index_done_callback(self) -> None:
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
