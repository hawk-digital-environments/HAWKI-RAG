"""Normalized startup authority must reach compatibility dispatch admission."""

from unittest.mock import Mock, patch

import pytest
import sqlalchemy as sa

from hawki_backend import main
from hawki_backend.ops.admission import prepare_clear
from hawki_backend.persistence.datasets import DatasetInactiveError, lock_active_dataset
from hawki_backend.query.settings import QueryConfigurationError, QuerySettings
from hawki_backend.text_ingestion.settings import TextIngestionSettings
from hawki_backend.workflow_control.repository import (
    OperationConflict,
    OperationRepository,
)
from hawki_backend.workflow_control.schema import operations

DATABASE_REPRESENTATIONS = [
    "database",
    "DATABASE",
    " database ",
    "database ",
    " database",
]


def startup_repository(env, monkeypatch, representation):
    monkeypatch.setenv("RAG_BACKEND_QUERY_DATASET_SOURCE", representation)
    monkeypatch.setenv("RAG_WORKFLOW_CONTROL_ENABLED", "true")
    monkeypatch.setattr(main, "build_engine", lambda _: env.engine)
    monkeypatch.setattr(main, "TemporalControlClient", Mock())
    monkeypatch.setattr(main, "load_workflow_settings", Mock())
    app = main.create_app(
        authenticator=Mock(),
        policy=Mock(),
        database_settings=Mock(),
        query_settings=QuerySettings(models={}),
        query_dependencies=Mock(),
        text_ingestion_settings=TextIngestionSettings(enabled=True),
        workflow_control_secret="test-only-secret",
        dataset_service=Mock(),
    )
    repository = app.state.workflow_control.repository
    assert app.state.text_ingestion_service is not None
    assert repository.dataset_source == "database"
    # Configuration is parsed once; later environment changes cannot remove fencing.
    monkeypatch.setenv("RAG_BACKEND_QUERY_DATASET_SOURCE", "environment")
    return repository


def payload(**extra):
    return {
        "workflow_input": {
            "dataset_id": "one",
            "ingestion": {"graph": True, "neo4j_namespace": "physical-graph-one"},
            **extra,
        }
    }


@pytest.mark.parametrize("representation", DATABASE_REPRESENTATIONS)
@pytest.mark.parametrize("action", ["source", "text", "schedule"])
def test_normalized_authority_locks_and_pending_clear_blocks(
    env, monkeypatch, representation, action
):
    repository = startup_repository(env, monkeypatch, representation)
    with patch(
        "hawki_backend.persistence.datasets.lock_active_dataset",
        wraps=lock_active_dataset,
    ) as locked:
        repository.prepare(
            action,
            "allowed",
            payload(text_writer_protocol=1, managed_document_id="managed"),
            ["allowed"],
        )
        assert locked.call_args.args[1:] == ("a", "one")
    prepare_clear(env.engine, "a", "one")
    for wire in [payload(text_writer_protocol=1), payload()]:
        with pytest.raises(DatasetInactiveError):
            repository.prepare(action, "blocked", wire, ["blocked"])
    with env.engine.connect() as connection:
        assert (
            connection.execute(
                sa.select(operations.c.workflow_id).where(
                    operations.c.workflow_id == "blocked"
                )
            ).first()
            is None
        )


@pytest.mark.parametrize("representation", DATABASE_REPRESENTATIONS)
def test_normalized_authority_rejects_noncanonical_and_unregistered_scope(
    env, monkeypatch, representation
):
    repository = startup_repository(env, monkeypatch, representation)
    with pytest.raises(OperationConflict, match="managed writer protocol"):
        repository.prepare("source", "unregistered", payload(), ["job"])
    wire = payload(text_writer_protocol=1)
    wire["workflow_input"]["ingestion"]["neo4j_namespace"] = "foreign"
    with pytest.raises(OperationConflict, match="canonical dataset scope"):
        repository.prepare("source", "foreign", wire, ["job"])


def test_invalid_authority_fails_startup_and_repository_closed(env, monkeypatch):
    monkeypatch.setenv("RAG_BACKEND_QUERY_DATASET_SOURCE", "databaze")
    with pytest.raises(QueryConfigurationError):
        main.create_app(authenticator=Mock(), policy=Mock())
    with pytest.raises(ValueError, match="canonical dataset authority"):
        OperationRepository(env.engine, dataset_source="databaze")
