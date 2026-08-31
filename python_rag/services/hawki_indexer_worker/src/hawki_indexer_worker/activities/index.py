"""Temporal transport wrappers for indexer application workflows."""

from __future__ import annotations

import logging
from functools import partial
from typing import Any

from temporalio import activity

from hawki_rag_contracts.pipeline.ingestion import (
    IndexActivityInput,
    ReadyActivityInput,
)
from hawki_rag_contracts.pipeline.temporal import (
    INDEX_MARKDOWN_ACTIVITY,
    MARK_SOURCE_READY_ACTIVITY,
)

from hawki_indexer_worker.adapters.composition import (
    build_ingest_workflow_dependencies,
)
from hawki_indexer_worker.adapters.providers.composition import (
    create_graph_extractor,
    get_provider,
)
from hawki_indexer_worker.adapters.status_callback import (
    create_callback_sender,
    report_status,
)
from hawki_indexer_worker.application.index_execution import (
    IndexActivityContext,
    IndexActivityDependencies,
    execute_index_activity,
)
from hawki_indexer_worker.application.ready_projection import (
    ReadyProjectionContext,
    project_source_ready,
)
from hawki_indexer_worker.indexing.orchestration import ingest_documents
from hawki_indexer_worker.settings import IndexerSettings

logger = logging.getLogger(__name__)


@activity.defn(name=INDEX_MARKDOWN_ACTIVITY)
def ingest_markdown_files(payload: dict[str, Any]) -> dict[str, Any]:
    """Decode one Temporal payload, execute indexing, and encode its result."""

    settings = IndexerSettings.from_env()
    activity_input = IndexActivityInput.model_validate(payload)
    with create_callback_sender(settings) as callback_sender:
        result = execute_index_activity(
            activity_input,
            context=IndexActivityContext(
                settings=settings,
                activity_info=activity.info(),
                heartbeat_sender=activity.heartbeat,
            ),
            dependencies=IndexActivityDependencies(
                artifact_store=None,
                graph_service=create_graph_extractor(
                    settings.rag_working_dir,
                    logger_obj=logger,
                ),
                provider_resolver=get_provider,
                workflow_dependencies=build_ingest_workflow_dependencies(),
                status_reporter=partial(report_status, sender=callback_sender),
                ingest_documents=ingest_documents,
            ),
        )
    return result.model_dump(mode="json")


@activity.defn(name=MARK_SOURCE_READY_ACTIVITY)
def mark_source_ready(payload: dict[str, Any]) -> dict[str, Any]:
    """Decode one Temporal payload, project readiness, and encode its result."""

    activity_input = ReadyActivityInput.model_validate(payload)
    settings = IndexerSettings.from_env()
    with create_callback_sender(settings) as callback_sender:
        projection = project_source_ready(
            activity_input,
            context=ReadyProjectionContext(
                settings=settings,
                status_reporter=partial(report_status, sender=callback_sender),
                activity_info=activity.info(),
            ),
        )
    return projection.to_wire()


__all__ = ["ingest_markdown_files", "mark_source_ready"]
