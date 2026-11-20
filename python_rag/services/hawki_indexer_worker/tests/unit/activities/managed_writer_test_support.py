"""Shared setup for managed writer registration and drain regressions."""

from types import SimpleNamespace

from hawki_indexer_worker.adapters.managed_writer import ManagedWriterRegistration
from hawki_indexer_worker.settings import IndexerSettings


def registration(tmp_path, attempt=1):
    settings = IndexerSettings(
        temporal_address="test:7233",
        temporal_namespace="test",
        task_queue="test",
        activity_worker_threads=1,
        callback_url="http://internal.test/api/internal/pipeline/worker-events",
        callback_secret="test-secret",
        callback_timeout_seconds=1,
        callback_retry_attempts=1,
        rag_working_dir=tmp_path,
    )
    info = SimpleNamespace(
        workflow_id="original",
        workflow_run_id="run-original",
        activity_id="7",
        attempt=attempt,
    )
    return ManagedWriterRegistration(
        settings,
        {
            "source_id": "source",
            "job_id": "job",
            "dataset_id": "dataset",
            "ingestion": {
                "collection": "historic",
                "graph": False,
                "neo4j_namespace": "historic",
            },
        },
        info,
    )
