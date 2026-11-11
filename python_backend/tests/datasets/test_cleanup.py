"""Durable dataset cleanup orchestration tests.

The coordinator is the only place that drives every source protocol and
decides whether an archived dataset may be tombstoned. These tests use the
in-memory store contract; the PostgreSQL tests prove the row fence itself.
"""

from __future__ import annotations

import asyncio

import pytest

from hawki_backend.datasets.cleanup import DatasetCleanupCoordinator
from hawki_backend.persistence.datasets import DatasetStoreUnavailableError

from .doubles import OTHER_TENANT, TENANT, FakeDatasetStore, record


class RecordingSource:
    """One source protocol double that can fail or clear its own rows."""

    def __init__(
        self,
        store: FakeDatasetStore,
        name: str,
        *,
        fail: bool = False,
    ) -> None:
        self.store = store
        self.name = name
        self.fail = fail
        self.calls: list[tuple[str, str]] = []

    async def cleanup_dataset(self, tenant_id: str, dataset_id: str) -> None:
        self.calls.append((tenant_id, dataset_id))
        if self.fail:
            raise RuntimeError("source cleanup is still busy")
        counts = self.store.source_counts.get((tenant_id, dataset_id))
        if counts is not None:
            counts[self.name] = 0


def _coordinator(
    store: FakeDatasetStore,
    *,
    managed: RecordingSource | None = None,
    text: RecordingSource | None = None,
    url: RecordingSource | None = None,
) -> DatasetCleanupCoordinator:
    return DatasetCleanupCoordinator(
        store,
        managed_documents=managed,
        text_ingestions=text,
        url_sources=url,
    )


def _run(coordinator: DatasetCleanupCoordinator, dataset_id: str = "dataset-a"):
    return asyncio.run(coordinator.run(TENANT, dataset_id))


def test_run_drives_every_source_then_tombstones() -> None:
    store = FakeDatasetStore()
    store.add(record(status="archived"))
    store.source_counts[(TENANT, "dataset-a")] = {
        "managed_documents": 1,
        "text_ingestions": 1,
        "url_sources": 1,
    }
    managed = RecordingSource(store, "managed_documents")
    text = RecordingSource(store, "text_ingestions")
    url = RecordingSource(store, "url_sources")

    result, pending, blocking = _run(
        _coordinator(store, managed=managed, text=text, url=url)
    )

    assert result.status == "deleted"
    assert pending is False
    assert blocking == {}
    assert managed.calls == [(TENANT, "dataset-a")]
    assert text.calls == [(TENANT, "dataset-a")]
    assert url.calls == [(TENANT, "dataset-a")]
    assert store.records[(TENANT, "dataset-a")].status == "deleted"


def test_a_failed_source_leaves_the_archived_row_retryable() -> None:
    store = FakeDatasetStore()
    store.add(record(status="archived"))
    store.source_counts[(TENANT, "dataset-a")] = {
        "managed_documents": 1,
        "text_ingestions": 0,
        "url_sources": 0,
    }
    managed = RecordingSource(store, "managed_documents", fail=True)

    result, pending, blocking = _run(_coordinator(store, managed=managed))

    assert result.status == "archived"
    assert pending is True
    assert blocking == {"managed_documents": 1}
    assert store.records[(TENANT, "dataset-a")].status == "archived"


def test_a_restarted_process_resumes_from_the_archived_row() -> None:
    store = FakeDatasetStore()
    store.add(record(status="archived"))
    store.source_counts[(TENANT, "dataset-a")] = {
        "managed_documents": 1,
        "text_ingestions": 0,
        "url_sources": 0,
    }
    failing = RecordingSource(store, "managed_documents", fail=True)
    _run(_coordinator(store, managed=failing))

    # A new process builds a new coordinator over the same durable row.
    healthy = RecordingSource(store, "managed_documents")
    result, pending, _ = _run(_coordinator(store, managed=healthy))

    assert result.status == "deleted"
    assert pending is False
    assert healthy.calls == [(TENANT, "dataset-a")]


def test_reconciliation_scans_every_tenants_archived_rows() -> None:
    store = FakeDatasetStore()
    store.add(record(tenant_id=TENANT, status="archived"))
    store.add(record(tenant_id=OTHER_TENANT, status="archived"))
    store.add(record(dataset_id="active", status="active"))
    store.add(record(dataset_id="deleted", status="deleted"))
    store.source_counts[(TENANT, "dataset-a")] = {
        "managed_documents": 0,
        "text_ingestions": 0,
        "url_sources": 0,
    }
    store.source_counts[(OTHER_TENANT, "dataset-a")] = {
        "managed_documents": 0,
        "text_ingestions": 0,
        "url_sources": 0,
    }

    processed = asyncio.run(_coordinator(store).reconcile(limit=10))

    assert processed == 2
    assert store.records[(TENANT, "dataset-a")].status == "deleted"
    assert store.records[(OTHER_TENANT, "dataset-a")].status == "deleted"
    assert store.records[(TENANT, "active")].status == "active"
    assert store.records[(TENANT, "deleted")].status == "deleted"


def test_a_tombstoned_row_is_never_finalized_twice() -> None:
    store = FakeDatasetStore()
    store.add(record(status="deleted"))

    result, pending, blocking = _run(_coordinator(store))

    assert result.status == "deleted"
    assert pending is False
    assert blocking == {}


def test_a_store_outage_during_reconciliation_is_raised() -> None:
    store = FakeDatasetStore()
    store.unavailable = True

    with pytest.raises(DatasetStoreUnavailableError):
        asyncio.run(_coordinator(store).reconcile())
