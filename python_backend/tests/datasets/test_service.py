"""Application-level tests for the authoritative dataset service."""

from __future__ import annotations

import asyncio

import pytest

from hawki_backend.datasets import (
    DatasetCreationConflictError,
    DatasetNotFoundError,
    DatasetNotReadyError,
    DatasetService,
    DatasetUnavailableError,
    GrantTokenInvalidError,
    GrantTokenRequiredError,
    InvalidDatasetRequestError,
    physical_scope,
)
from hawki_backend.persistence.datasets import DatasetInactiveError

from .doubles import (
    OTHER_TENANT,
    TENANT,
    USER_ID,
    FakeCatalog,
    FakeDatasetCleanup,
    FakeDatasetStore,
    record,
    service_principal,
    user_principal,
)


def _service(
    store: FakeDatasetStore | None = None,
    catalog: FakeCatalog | None = None,
    cleanup: FakeDatasetCleanup | None = None,
) -> DatasetService:
    return DatasetService(
        store or FakeDatasetStore(),
        catalog or FakeCatalog({"hawki_dataset_a"}, {"hawki_dataset_a": 3}),
        embedding_provider="ollama",
        embedding_model="bge-m3",
        cleanup=cleanup,
    )


def _delete(service: DatasetService, dataset_id: str = "dataset-a"):
    return asyncio.run(service.delete(TENANT, dataset_id))


# ------------------------------------------------------------------- creation


def test_creation_derives_identity_display_name_and_physical_scope() -> None:
    store = FakeDatasetStore()
    service = _service(store)

    created = service.create(TENANT, {})

    assert created.created is True
    assert created.grant_token is not None
    assert created.grant_token.startswith("dgt_")
    assert len(created.grant_token) == 68
    assert created.record.dataset_id == "default"
    assert created.record.name == "Default"
    assert created.record.status == "active"
    expected_collection, expected_namespace = physical_scope(TENANT, "default")
    assert created.record.qdrant_collection == expected_collection
    assert created.record.neo4j_namespace == expected_namespace
    assert expected_collection.startswith("hawki_default_")
    assert len(expected_collection) <= 191
    assert created.record.embedding_provider == "ollama"
    assert created.record.embedding_model == "bge-m3"
    assert store.issued_tokens == [created.grant_token]


def test_creation_derives_a_tenant_bound_physical_scope() -> None:
    first = _service().create(TENANT, {"dataset_id": "Dataset-A"})
    second = _service().create(OTHER_TENANT, {"dataset_id": "Dataset-A"})

    assert first.record.qdrant_collection == first.record.neo4j_namespace
    assert first.record.qdrant_collection != second.record.qdrant_collection
    assert first.record.qdrant_collection == physical_scope(TENANT, "Dataset-A")[0]


def test_creation_accepts_the_documented_alias_fields() -> None:
    service = _service()

    created = service.create(
        TENANT,
        {
            "datasetId": "Alias-1",
            "name": "Explicit Name",
            "description": "  A description  ",
        },
    )

    assert created.record.dataset_id == "Alias-1"
    assert created.record.name == "Explicit Name"
    assert created.record.description == "A description"
    assert created.record.qdrant_collection == physical_scope(TENANT, "Alias-1")[0]
    assert created.record.neo4j_namespace == physical_scope(TENANT, "Alias-1")[1]


@pytest.mark.parametrize(
    "field",
    [
        "qdrant_collection",
        "qdrantCollection",
        "neo4j_namespace",
        "neo4jNamespace",
        "physical_scope",
        "embedding_provider",
        "embedding_model",
        "graph_enabled",
        "tenant_id",
    ],
)
def test_creation_refuses_every_caller_selected_scope_field(field: str) -> None:
    store = FakeDatasetStore()

    with pytest.raises(InvalidDatasetRequestError) as error:
        _service(store).create(TENANT, {"dataset_id": "dataset-a", field: "chosen"})

    assert error.value.status_code == 422
    assert not store.records
    assert not store.issued_tokens


def test_compatible_creation_replay_never_issues_a_second_token() -> None:
    store = FakeDatasetStore()
    service = _service(store)
    first = service.create(TENANT, {"dataset_id": "dataset-a", "name": "Dataset A"})

    second = service.create(TENANT, {"dataset_id": "dataset-a", "name": "Dataset A"})

    assert first.created is True
    assert second.created is False
    assert second.grant_token is None
    assert store.issued_tokens == [first.grant_token]


def test_incompatible_creation_replay_is_a_conflict() -> None:
    service = _service()
    service.create(TENANT, {"dataset_id": "dataset-a", "name": "Dataset A"})

    with pytest.raises(DatasetCreationConflictError) as error:
        service.create(TENANT, {"dataset_id": "dataset-a", "name": "Other"})

    assert error.value.error_code == "dataset_creation_conflict"
    assert error.value.status_code == 409


def test_a_non_active_creation_status_is_a_conflict() -> None:
    service = _service()

    with pytest.raises(DatasetCreationConflictError):
        service.create(TENANT, {"dataset_id": "dataset-a", "status": "archived"})


def test_a_physical_scope_collision_is_still_refused_by_the_store() -> None:
    store = FakeDatasetStore()
    target = physical_scope(TENANT, "default")
    store.add(record(dataset_id="one", collection=target[0], namespace=target[1]))
    service = _service(store)

    with pytest.raises(DatasetCreationConflictError) as error:
        service.create(TENANT, {"dataset_id": "default"})

    assert "physical_scope" in str(error.value)


def test_an_inactive_identity_is_never_revived() -> None:
    store = FakeDatasetStore()
    store.add(record(status="archived"))
    service = _service(store)

    with pytest.raises(DatasetCreationConflictError):
        service.create(TENANT, {"dataset_id": "dataset-a"})


def test_creation_maps_a_store_outage_to_the_public_failure() -> None:
    store = FakeDatasetStore()
    store.unavailable = True

    with pytest.raises(DatasetUnavailableError):
        _service(store).create(TENANT, {"dataset_id": "dataset-a"})


# --------------------------------------------------------------- list and show


def test_list_returns_only_the_tenants_active_datasets() -> None:
    store = FakeDatasetStore()
    store.add(record(dataset_id="dataset-a", name="A"))
    store.add(record(dataset_id="dataset-b", name="B"))
    store.add(record(dataset_id="archived", status="archived"))
    store.add(record(dataset_id="other", tenant_id=OTHER_TENANT))
    service = _service(store)

    payloads = service.list(TENANT)

    assert [payload["dataset_id"] for payload in payloads] == ["dataset-b", "dataset-a"]
    assert all(payload["status"] == "active" for payload in payloads)
    assert payloads[0]["id"] is None
    assert payloads[0]["task_count"] == 0
    assert "tasks" not in payloads[0]


def test_list_reports_a_missing_collection_as_not_created() -> None:
    store = FakeDatasetStore()
    store.add(record(dataset_id="dataset-a", collection="missing"))
    service = _service(store, FakeCatalog({"other"}))

    payload = service.list(TENANT)[0]

    assert payload["graph_stats"]["qdrant"] == {
        "ok": True,
        "collection": "missing",
        "points": 0,
        "status": "not_created",
        "message": "Collection not created yet",
    }


def test_list_fails_closed_to_unknown_qdrant_statistics() -> None:
    store = FakeDatasetStore()
    store.add(record())
    service = _service(store, FakeCatalog(None))

    payload = service.list(TENANT)[0]

    assert payload["graph_stats"]["qdrant"]["ok"] is False
    assert payload["graph_stats"]["qdrant"]["points"] is None
    assert payload["graph_stats"]["neo4j"]["ok"] is False


def test_show_includes_details_for_an_active_dataset() -> None:
    store = FakeDatasetStore()
    store.add(record())
    service = _service(store)

    payload = service.show(TENANT, "dataset-a")

    assert payload is not None
    assert payload["dataset_id"] == "dataset-a"
    assert payload["tasks"] == []
    assert payload["ingestion_history"] == []
    assert payload["graph_stats"]["qdrant"]["points"] == 3


@pytest.mark.parametrize("dataset_id", ["missing", "bad id"])
def test_show_refuses_an_unknown_or_malformed_dataset(dataset_id: str) -> None:
    store = FakeDatasetStore()
    service = _service(store)

    with pytest.raises(DatasetNotFoundError):
        service.show(TENANT, dataset_id)


def test_show_refuses_an_inactive_dataset() -> None:
    store = FakeDatasetStore()
    store.add(record(status="archived"))

    with pytest.raises(DatasetNotFoundError):
        _service(store).show(TENANT, "dataset-a")


def test_datasets_of_another_tenant_are_invisible() -> None:
    store = FakeDatasetStore()
    store.add(record(dataset_id="dataset-a", tenant_id=TENANT))
    store.add(record(dataset_id="dataset-a", tenant_id=OTHER_TENANT))
    service = _service(store)

    assert service.show(OTHER_TENANT, "dataset-a")["dataset_id"] == "dataset-a"
    with pytest.raises(DatasetNotFoundError):
        service.show("tenant-c", "dataset-a")
    assert [item["dataset_id"] for item in service.list(TENANT)] == ["dataset-a"]
    assert [item["dataset_id"] for item in service.list(OTHER_TENANT)] == ["dataset-a"]


def test_list_maps_a_store_outage_to_the_public_failure() -> None:
    store = FakeDatasetStore()
    store.unavailable = True

    with pytest.raises(DatasetUnavailableError):
        _service(store).list(TENANT)


# -------------------------------------------------------------------- deletion


def test_deletion_is_archive_first_and_tombstones_an_empty_dataset() -> None:
    store = FakeDatasetStore()
    store.add(record())
    cleanup = FakeDatasetCleanup()
    service = _service(store, cleanup=cleanup)

    result = _delete(service)

    assert result is not None
    assert result.cleanup_pending is False
    assert result.record.status == "deleted"
    assert cleanup.runs == [(TENANT, "dataset-a")]


def test_deletion_runs_every_source_protocol_before_tombstoning() -> None:
    store = FakeDatasetStore()
    store.add(record())
    store.source_counts[(TENANT, "dataset-a")] = {
        "managed_documents": 2,
        "text_ingestions": 1,
        "url_sources": 0,
    }
    cleanup = FakeDatasetCleanup(
        pending=True,
        blocking={"managed_documents": 2, "text_ingestions": 1},
    )
    service = _service(store, cleanup=cleanup)

    pending = _delete(service)

    assert pending is not None
    assert pending.cleanup_pending is True
    assert pending.record.status == "archived"
    assert dict(pending.blocking_sources) == {
        "managed_documents": 2,
        "text_ingestions": 1,
    }
    assert cleanup.runs == [(TENANT, "dataset-a")]


def test_deletion_without_a_coordinator_fails_closed_on_blocking_sources() -> None:
    store = FakeDatasetStore()
    store.add(record())
    store.source_counts[(TENANT, "dataset-a")] = {
        "managed_documents": 1,
        "text_ingestions": 0,
        "url_sources": 0,
    }

    with pytest.raises(DatasetUnavailableError):
        _delete(_service(store))

    assert store.records[(TENANT, "dataset-a")].status == "archived"


def test_deletion_without_a_coordinator_still_tombstones_an_empty_dataset() -> None:
    store = FakeDatasetStore()
    store.add(record())

    result = _delete(_service(store))

    assert result is not None
    assert result.record.status == "deleted"


def test_deleting_a_tombstone_is_idempotent() -> None:
    store = FakeDatasetStore()
    store.add(record(status="deleted"))
    cleanup = FakeDatasetCleanup()

    result = _delete(_service(store, cleanup=cleanup))

    assert result is not None
    assert result.record.status == "deleted"
    assert result.cleanup_pending is False
    assert cleanup.runs == []


def test_deleting_an_unknown_dataset_returns_none() -> None:
    assert _delete(_service()) is None


def test_deletion_maps_a_store_outage_to_the_public_failure() -> None:
    store = FakeDatasetStore()
    store.add(record())
    store.unavailable = True

    with pytest.raises(DatasetUnavailableError):
        _delete(_service(store))


# ---------------------------------------------------------------------- grants


def test_query_grant_requires_an_available_collection_with_points() -> None:
    store = FakeDatasetStore()
    store.add(record())
    service = _service(store, FakeCatalog({"hawki_dataset_a"}, {"hawki_dataset_a": 0}))

    with pytest.raises(DatasetNotReadyError):
        service.grant_query(user_principal(), "dataset-a")
    assert not store.grants

    service = _service(store, FakeCatalog({"hawki_dataset_a"}, {"hawki_dataset_a": 1}))
    service.grant_query(user_principal(), "dataset-a")

    assert (TENANT, "dataset-a", "user", USER_ID, "query") in store.grants
    assert (TENANT, "dataset-a", "user", USER_ID, "ingest") not in store.grants


def test_query_grant_fails_closed_when_the_catalog_is_unknown() -> None:
    store = FakeDatasetStore()
    store.add(record())

    with pytest.raises(DatasetNotReadyError):
        _service(store, FakeCatalog(None)).grant_query(user_principal(), "dataset-a")


def test_existing_query_grant_stays_idempotent_during_a_catalog_outage() -> None:
    store = FakeDatasetStore()
    store.add(record())
    principal = user_principal()
    store.grants.add((TENANT, "dataset-a", "user", USER_ID, "query"))
    service = _service(store, FakeCatalog(None))

    service.grant_query(principal, "dataset-a")

    assert store.grants == {(TENANT, "dataset-a", "user", USER_ID, "query")}


@pytest.mark.parametrize("dataset_id", ["missing", "bad id"])
def test_query_grant_refuses_an_unknown_dataset(dataset_id: str) -> None:
    with pytest.raises(DatasetNotFoundError):
        _service().grant_query(user_principal(), dataset_id)


def test_ingest_grant_requires_the_bootstrap_token() -> None:
    store = FakeDatasetStore()
    store.add(record())
    service = _service(store)

    with pytest.raises(GrantTokenRequiredError):
        service.grant_ingest(user_principal(), "dataset-a", None)
    assert not store.grants


def test_ingest_grant_redeems_a_bound_token_exactly_once() -> None:
    store = FakeDatasetStore()
    store.add(record())
    token = service_token(store)
    service = _service(store)

    replayed = service.grant_ingest(user_principal(), "dataset-a", token)

    assert replayed is False
    assert (TENANT, "dataset-a", "user", USER_ID, "ingest") in store.grants

    with pytest.raises(GrantTokenInvalidError):
        service.grant_ingest(user_principal(subject="user-2"), "dataset-a", token)


def test_an_existing_ingest_grant_replays_without_a_token() -> None:
    store = FakeDatasetStore()
    store.add(record())
    principal = user_principal()
    store.grants.add((TENANT, "dataset-a", "user", USER_ID, "ingest"))
    service = _service(store)

    assert service.grant_ingest(principal, "dataset-a", None) is True
    assert store.grants == {(TENANT, "dataset-a", "user", USER_ID, "ingest")}


def test_a_token_of_another_dataset_is_refused() -> None:
    store = FakeDatasetStore()
    store.add(record(dataset_id="dataset-a"))
    store.add(record(dataset_id="dataset-b", collection="hawki_b", namespace="hawki_b"))
    token = service_token(store)
    service = _service(store)

    with pytest.raises(GrantTokenInvalidError):
        service.grant_ingest(user_principal(), "dataset-b", token)


def test_grant_failures_of_the_store_are_not_refusals() -> None:
    store = FakeDatasetStore()
    store.add(record())
    store.grants.add((TENANT, "dataset-a", "user", USER_ID, "query"))
    store.unavailable = True

    with pytest.raises(DatasetUnavailableError):
        _service(store).grant_query(user_principal(), "dataset-a")
    with pytest.raises(DatasetUnavailableError):
        _service(store).grant_ingest(user_principal(), "dataset-a", None)


def test_a_grant_write_after_archive_is_not_found() -> None:
    store = FakeDatasetStore()
    store.add(record())
    store.grant_error = DatasetInactiveError()

    with pytest.raises(DatasetNotFoundError):
        _service(store).grant_query(user_principal(), "dataset-a")


def test_a_token_redemption_after_archive_is_not_found() -> None:
    store = FakeDatasetStore()
    store.add(record())
    token = service_token(store)
    store.redeem_error = DatasetInactiveError()

    with pytest.raises(DatasetNotFoundError):
        _service(store).grant_ingest(user_principal(), "dataset-a", token)


def test_reconciliation_is_a_noop_without_a_coordinator() -> None:
    assert asyncio.run(_service().reconcile_cleanup()) == 0


def test_reconciliation_delegates_to_the_coordinator() -> None:
    cleanup = FakeDatasetCleanup()

    assert asyncio.run(_service(cleanup=cleanup).reconcile_cleanup(limit=5)) == 0
    assert cleanup.reconciliations == 1


# ------------------------------------------------------------- query visibility


def test_query_datasets_are_exactly_the_available_query_grants() -> None:
    store = FakeDatasetStore()
    store.add(record(dataset_id="b", name="B", collection="hawki_b"))
    store.add(record(dataset_id="a", name="A", collection="hawki_a"))
    store.add(record(dataset_id="ingest-only", name="C", collection="hawki_c"))
    store.add(record(dataset_id="missing", name="D", collection="hawki_missing"))
    store.add(record(dataset_id="other", name="E", tenant_id=OTHER_TENANT))
    principal = user_principal()
    for dataset_id in ("a", "b", "ingest-only", "missing", "other"):
        store.grants.add((TENANT, dataset_id, "user", USER_ID, "query"))
    store.grants.add((TENANT, "ingest-only", "user", USER_ID, "ingest"))
    service = _service(store, FakeCatalog({"hawki_a", "hawki_b", "hawki_c"}))

    assert service.query_datasets(principal) == [
        {"dataset_id": "a", "name": "A"},
        {"dataset_id": "b", "name": "B"},
        {"dataset_id": "ingest-only", "name": "C"},
    ]


def test_query_datasets_fail_closed_when_the_catalog_is_unknown() -> None:
    store = FakeDatasetStore()
    store.add(record())
    store.grants.add((TENANT, "dataset-a", "user", USER_ID, "query"))
    service = _service(store, FakeCatalog(None))

    assert service.query_datasets(user_principal()) == []


def test_query_datasets_are_empty_for_a_service_principal() -> None:
    store = FakeDatasetStore()
    store.add(record())
    store.grants.add((TENANT, "dataset-a", "service", "hawki-bff", "query"))
    service = _service(store)

    assert service.query_datasets(service_principal(scopes=("rag:query",))) == []


def test_query_datasets_do_not_cross_tenants() -> None:
    store = FakeDatasetStore()
    store.add(record(dataset_id="dataset-a", tenant_id=TENANT))
    store.add(record(dataset_id="dataset-a", tenant_id=OTHER_TENANT))
    store.grants.update(
        {
            (TENANT, "dataset-a", "user", USER_ID, "query"),
            (OTHER_TENANT, "dataset-a", "user", USER_ID, "query"),
        }
    )
    service = _service(store)

    assert len(service.query_datasets(user_principal())) == 1
    assert len(service.query_datasets(user_principal(tenant_id=OTHER_TENANT))) == 1


def test_query_datasets_map_a_store_outage_to_the_public_failure() -> None:
    store = FakeDatasetStore()
    store.unavailable = True

    with pytest.raises(DatasetUnavailableError):
        _service(store).query_datasets(user_principal())


# ---------------------------------------------------------------------- helpers


def service_token(store: FakeDatasetStore, dataset_id: str = "dataset-a") -> str:
    """Issue a token through the fake store and return its plaintext."""

    return store.issue_token(TENANT, dataset_id)
