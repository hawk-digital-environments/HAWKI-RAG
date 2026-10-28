"""Tests for the Python-owned database schema.

The schema is the design artifact the runtime query and the migration both
read. These tests pin its shape, so that a change to either of them cannot
quietly weaken the isolation the schema provides.
"""

import sqlalchemy as sa

from hawki_backend.persistence.schema import (
    ACTIVE_STATUS,
    ARCHIVED_STATUS,
    DATASET_STATUSES,
    DELETED_STATUS,
    PERMISSIONS,
    PRINCIPAL_TYPES,
    SCHEMA_NAME,
    dataset_grants,
    datasets,
    metadata,
)


def test_the_backend_owns_one_named_schema() -> None:
    assert SCHEMA_NAME == "hawki_backend"
    assert metadata.schema == SCHEMA_NAME
    assert datasets.schema == SCHEMA_NAME
    assert dataset_grants.schema == SCHEMA_NAME


def test_the_dataset_status_values_are_the_three_known_ones() -> None:
    assert DATASET_STATUSES == (ACTIVE_STATUS, ARCHIVED_STATUS, DELETED_STATUS)
    assert DATASET_STATUSES == ("active", "archived", "deleted")


def test_the_permissions_are_query_and_ingest_only() -> None:
    assert PERMISSIONS == ("query", "ingest")
    assert "delete" not in PERMISSIONS


def test_the_principal_kinds_are_user_and_service_only() -> None:
    assert PRINCIPAL_TYPES == ("user", "service")


def test_the_dataset_table_carries_only_what_authorization_needs() -> None:
    assert set(datasets.c.keys()) == {
        "tenant_id",
        "dataset_id",
        "status",
        "created_at",
        "updated_at",
    }


def test_the_dataset_table_carries_no_storage_target() -> None:
    for column in (
        "qdrant_collection",
        "neo4j_namespace",
        "embedding_provider",
        "embedding_model",
        "name",
        "description",
        "owner_id",
    ):
        assert column not in datasets.c


def test_the_grant_table_carries_the_five_part_identity() -> None:
    assert set(dataset_grants.c.keys()) == {
        "tenant_id",
        "dataset_id",
        "principal_type",
        "principal_id",
        "permission",
        "created_at",
    }


def test_the_grant_identity_is_the_principal_key() -> None:
    primary = [column.name for column in dataset_grants.primary_key.columns]

    assert primary == [
        "tenant_id",
        "dataset_id",
        "principal_type",
        "principal_id",
        "permission",
    ]


def test_the_dataset_identity_is_tenant_and_dataset() -> None:
    primary = [column.name for column in datasets.primary_key.columns]

    assert primary == ["tenant_id", "dataset_id"]


def test_the_grant_references_the_dataset_by_tenant_and_dataset() -> None:
    foreign_keys = list(dataset_grants.foreign_key_constraints)

    assert len(foreign_keys) == 1
    constraint = foreign_keys[0]
    assert [element.parent.name for element in constraint.elements] == [
        "tenant_id",
        "dataset_id",
    ]
    targets = [
        (element.column.table.schema, element.column.table.name, element.column.name)
        for element in constraint.elements
    ]
    assert targets == [
        (SCHEMA_NAME, "datasets", "tenant_id"),
        (SCHEMA_NAME, "datasets", "dataset_id"),
    ]
    assert constraint.ondelete == "CASCADE"


def test_the_status_is_limited_to_the_known_values() -> None:
    constraints = [
        constraint
        for constraint in datasets.constraints
        if isinstance(constraint, sa.CheckConstraint)
    ]

    assert len(constraints) == 1
    for status in DATASET_STATUSES:
        assert status in str(constraints[0].sqltext)


def test_the_grant_permission_is_limited_to_the_known_values() -> None:
    constraints = [
        constraint
        for constraint in dataset_grants.constraints
        if isinstance(constraint, sa.CheckConstraint)
    ]

    assert len(constraints) == 2
    text = " ".join(str(constraint.sqltext) for constraint in constraints)
    for permission in PERMISSIONS:
        assert permission in text
    assert "delete" not in text


def test_the_grant_principal_kind_is_limited_to_the_known_values() -> None:
    constraints = [
        constraint
        for constraint in dataset_grants.constraints
        if isinstance(constraint, sa.CheckConstraint)
    ]

    text = " ".join(str(constraint.sqltext) for constraint in constraints)
    for principal_type in PRINCIPAL_TYPES:
        assert principal_type in text


def test_the_principal_lookup_index_exists() -> None:
    indexes = {index.name: index for index in dataset_grants.indexes}

    assert "ix_dataset_grants_principal" in indexes
    assert [
        column.name for column in indexes["ix_dataset_grants_principal"].columns
    ] == [
        "tenant_id",
        "principal_type",
        "principal_id",
    ]


def test_every_column_of_the_grant_table_is_required() -> None:
    for column in dataset_grants.c:
        assert not column.nullable


def test_every_column_of_the_dataset_table_is_required() -> None:
    for column in datasets.c:
        assert not column.nullable
