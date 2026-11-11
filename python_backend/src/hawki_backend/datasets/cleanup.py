"""Durable dataset-deletion orchestration across every owned source protocol.

A dataset is hidden by an archive, never deleted by a single metadata write.
The archived dataset row is the durable work item: this coordinator enumerates
the dataset's Python-owned sources, delegates to each source's existing
deletion state machine through a narrow internal cleanup authority, re-counts
the sources, and only then tombstones the dataset.

The coordinator never grants or checks a user privilege. It is reachable only
from the internal dataset authority API and from the startup reconciler, and
it always acts within one authenticated tenant.
"""

from __future__ import annotations

import logging
from collections.abc import Mapping
from typing import Protocol

from hawki_backend.persistence.datasets import (
    DatasetRecord,
    DatasetStore,
    DatasetStoreUnavailableError,
)
from hawki_backend.persistence.schema import DELETED_STATUS

_logger = logging.getLogger(__name__)

SOURCE_NAMES = ("managed_documents", "text_ingestions", "url_sources")


class DatasetSourceCleanup(Protocol):
    """One owned source protocol that can clean up a whole dataset."""

    async def cleanup_dataset(self, tenant_id: str, dataset_id: str) -> None:
        """Drive every non-deleted source of this dataset to ``deleted``."""
        ...


class DatasetCleanupCoordinator:
    """Archive-first, restart-safe dataset deletion orchestration."""

    def __init__(
        self,
        store: DatasetStore,
        *,
        managed_documents: DatasetSourceCleanup | None = None,
        text_ingestions: DatasetSourceCleanup | None = None,
        url_sources: DatasetSourceCleanup | None = None,
    ) -> None:
        self._store = store
        self._managed_documents = managed_documents
        self._text_ingestions = text_ingestions
        self._url_sources = url_sources

    async def run(
        self,
        tenant_id: str,
        dataset_id: str,
    ) -> tuple[DatasetRecord, bool, Mapping[str, int]]:
        """Attempt cleanup once and return the exact durable outcome.

        Source failures are logged by class and leave the archived row in
        place, so the next scan retries through the same deletion state
        machines. They are never reported as a completed deletion.
        """

        for name, source in (
            ("managed_documents", self._managed_documents),
            ("text_ingestions", self._text_ingestions),
            ("url_sources", self._url_sources),
        ):
            if source is None:
                continue
            try:
                await source.cleanup_dataset(tenant_id, dataset_id)
            except Exception as error:  # noqa: BLE001 - durable retry is the point
                _logger.warning(
                    "dataset cleanup remains pending",
                    extra={
                        "dataset_id": dataset_id,
                        "source": name,
                        "error_type": type(error).__name__,
                    },
                )

        record = self._store.get(tenant_id, dataset_id)
        if record is None:
            raise DatasetStoreUnavailableError()
        counts = self._store.owned_source_counts(tenant_id, dataset_id)
        blocking = {name: value for name, value in counts.items() if value}
        if blocking or record.status == DELETED_STATUS:
            return record, bool(blocking), blocking

        final = self._store.finalize_deletion(tenant_id, dataset_id)
        return final or record, False, {}

    async def reconcile(self, *, limit: int = 100) -> int:
        """Re-drive every archived dataset; safe to run in more than one worker."""

        processed = 0
        for record in self._store.list_archived(limit):
            try:
                await self.run(record.tenant_id, record.dataset_id)
            except DatasetStoreUnavailableError:
                raise
            except Exception as error:  # noqa: BLE001 - keep scanning other rows
                _logger.warning(
                    "archived dataset reconciliation failed",
                    extra={
                        "dataset_id": record.dataset_id,
                        "error_type": type(error).__name__,
                    },
                )
            processed += 1
        return processed


__all__ = ["DatasetCleanupCoordinator", "DatasetSourceCleanup", "SOURCE_NAMES"]
