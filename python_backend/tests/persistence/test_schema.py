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
    dataset_grant_tokens,
    dataset_grants,
    datasets,
    metadata,
)


def test_the_backend_owns_one_named_schema() -> None:
    assert SCHEMA_NAME == "hawki_backend"
    assert metadata.schema == SCHEMA_NAME
    assert datasets.schema == SCHEMA_NAME
    assert dataset_grants.schema == SCHEMA_NAME
    assert dataset_grant_tokens.schema == SCHEMA_NAME


def test_the_dataset_status_values_are_the_three_known_ones() -> None:
    assert DATASET_STATUSES == (ACTIVE_STATUS, ARCHIVED_STATUS, DELETED_STATUS)
    assert DATASET_STATUSES == ("active", "archived", "deleted")


def test_the_permissions_are_query_and_ingest_only() -> None:
    assert PERMISSIONS == ("query", "ingest")
    assert "delete" not in PERMISSIONS


def test_the_principal_kinds_are_user_and_service_only() -> None:
    assert PRINCIPAL_TYPES == ("user", "service")


def test_the_dataset_table_carries_canonical_metadata_and_physical_scope() -> None:
    assert set(datasets.c.keys()) == {
        "tenant_id",
        "dataset_id",
        "name",
        "description",
        "status",
        "qdrant_collection",
        "neo4j_namespace",
        "embedding_provider",
        "embedding_model",
        "graph_enabled",
        "deleted_at",
        "created_at",
        "updated_at",
    }


def test_the_dataset_physical_scope_is_server_owned() -> None:
    for column in (
        "qdrant_collection",
        "embedding_provider",
        "embedding_model",
    ):
        assert column in datasets.c
        assert not datasets.c[column].nullable
    assert datasets.c["neo4j_namespace"].nullable
    assert datasets.c["graph_enabled"].nullable is False


def test_the_dataset_physical_targets_are_unique() -> None:
    uniques = {
        constraint.name: [column.name for column in constraint.columns]
        for constraint in datasets.constraints
        if isinstance(constraint, sa.UniqueConstraint)
    }

    assert uniques == {
        "uq_datasets_qdrant_collection": ["qdrant_collection"],
        "uq_datasets_neo4j_namespace": ["neo4j_namespace"],
    }


def test_the_dataset_tombstone_stamp_is_optional() -> None:
    assert datasets.c["deleted_at"].nullable
    assert datasets.c["description"].nullable
    assert not datasets.c["name"].nullable
    assert not datasets.c["status"].nullable


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


def test_every_required_dataset_column_is_required() -> None:
    optional = {"description", "neo4j_namespace", "deleted_at"}

    for column in datasets.c:
        if column.name in optional:
            assert column.nullable
        else:
            assert not column.nullable


def test_the_grant_token_table_stores_only_a_hash_and_lifecycle() -> None:
    assert set(dataset_grant_tokens.c.keys()) == {
        "token_hash",
        "tenant_id",
        "dataset_id",
        "expires_at",
        "consumed_at",
        "created_at",
        "updated_at",
    }
    assert not dataset_grant_tokens.c["token_hash"].nullable
    assert not dataset_grant_tokens.c["expires_at"].nullable
    assert dataset_grant_tokens.c["consumed_at"].nullable


def test_the_grant_token_identity_is_the_hash() -> None:
    primary = [column.name for column in dataset_grant_tokens.primary_key.columns]

    assert primary == ["token_hash"]


def test_the_grant_token_is_bound_to_the_dataset_by_foreign_key() -> None:
    foreign_keys = list(dataset_grant_tokens.foreign_key_constraints)

    assert len(foreign_keys) == 1
    constraint = foreign_keys[0]
    assert [element.parent.name for element in constraint.elements] == [
        "tenant_id",
        "dataset_id",
    ]
    assert constraint.ondelete == "CASCADE"


def test_the_grant_token_hash_length_is_pinned() -> None:
    constraints = [
        constraint
        for constraint in dataset_grant_tokens.constraints
        if isinstance(constraint, sa.CheckConstraint)
    ]

    assert len(constraints) == 1
    assert "length(token_hash)" in str(constraints[0].sqltext)
    assert "64" in str(constraints[0].sqltext)


def test_the_grant_token_dataset_lookup_index_exists() -> None:
    indexes = {index.name: index for index in dataset_grant_tokens.indexes}

    assert "ix_dataset_grant_tokens_dataset" in indexes
    assert [
        column.name for column in indexes["ix_dataset_grant_tokens_dataset"].columns
    ] == ["tenant_id", "dataset_id"]
