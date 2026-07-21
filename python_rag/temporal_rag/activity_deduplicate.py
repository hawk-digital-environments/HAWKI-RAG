"""Pre-conversion deduplication activity entrypoint."""

from __future__ import annotations

from datetime import UTC, datetime
from typing import Any

from temporalio import activity

from temporal_rag.deduplication import (
    SourceDeduplicationPlan,
    SourceDeduplicationStore,
    discover_source_documents,
    write_plan,
)
from temporal_rag.logging import log_event
from temporal_rag.metadata import AppMetadataStore
from temporal_rag.settings import TemporalRagSettings


def _claim_token(workflow_input: dict[str, Any]) -> str:
    explicit = workflow_input.get("deduplication")
    if isinstance(explicit, dict):
        value = explicit.get("claim_token")
        if isinstance(value, str) and value.strip():
            return value.strip()

    try:
        info = activity.info()
        workflow_id = str(getattr(info, "workflow_id", "") or "").strip()
        run_id = str(getattr(info, "workflow_run_id", "") or "").strip()
        if workflow_id and run_id:
            return f"{workflow_id}:{run_id}"
        if run_id:
            return run_id
    except RuntimeError:
        pass

    job_id = str(workflow_input.get("job_id") or "").strip()
    source_id = str(workflow_input.get("source_id") or "").strip()
    return job_id or source_id


@activity.defn(name="classify_source_documents")
def classify_source_documents(payload: dict[str, Any]) -> dict[str, Any]:
    """Atomically classify raw source documents before conversion starts."""

    from temporal_rag import activities as support

    workflow_input = dict(payload["workflow_input"])
    scrape_result = dict(payload["scrape_result"])
    settings = TemporalRagSettings.from_env()
    metadata = AppMetadataStore(settings)
    source_id = str(workflow_input["source_id"])
    raw_dir = str(scrape_result.get("raw_dir") or workflow_input["raw_output_path"])
    claim_token = _claim_token(workflow_input)
    details = {"raw_dir": raw_dir, "claim_token": claim_token}
    metadata.mark_phase(workflow_input, "classify_source_documents", "started", details)
    log_event(
        support.logger,
        "dedup:start",
        source_id=source_id,
        task_id=workflow_input.get("task_id"),
        job_id=workflow_input.get("job_id"),
    )

    try:
        fingerprints = discover_source_documents(
            workflow_input,
            scrape_result,
            progress_callback=lambda path, bytes_read: _heartbeat(
                str(path),
                f"hashed_bytes:{bytes_read}",
            ),
        )
        for fingerprint in fingerprints:
            _heartbeat(fingerprint.document_id, fingerprint.content_hash)

        deduplication = workflow_input.get("deduplication")
        force = bool(deduplication.get("force", False)) if isinstance(deduplication, dict) else False
        store = SourceDeduplicationStore(settings)
        claimed = store.claim(
            fingerprints,
            claim_token=claim_token,
            task_id=_optional_string(workflow_input.get("task_id")),
            job_id=_optional_string(workflow_input.get("job_id")),
            force=force,
        )
        plan = SourceDeduplicationPlan(
            claim_token=claim_token,
            scope_key=claimed[0].scope_key,
            source_id=source_id,
            documents=tuple(claimed),
            created_at=datetime.now(UTC).isoformat(),
        )
        plan_path = write_plan(plan, raw_dir)
        result = plan.to_payload(plan_path)
    except Exception as exc:
        support._record_activity_exception(
            metadata,
            workflow_input,
            "classify_source_documents",
            exc,
            raw_dir=raw_dir,
        )
        raise

    for document in plan.documents:
        log_event(
            support.logger,
            "dedup:decision",
            scope_key=document.scope_key,
            doc_id=document.document_id,
            content_hash=document.content_hash,
            decision=document.decision,
            reason=_decision_reason(document.decision),
            previous_content_hash=document.previous_content_hash,
            source_id=document.source_id,
            skip_action="conversion_and_ingestion" if not document.requires_processing else None,
        )
    metadata.mark_phase(workflow_input, "classify_source_documents", "success", result)
    log_event(support.logger, "dedup:end", **result)
    return result


def _optional_string(value: Any) -> str | None:
    if isinstance(value, (str, int, float)) and str(value).strip():
        return str(value).strip()
    return None


def _decision_reason(decision: str) -> str:
    return {
        "new": "document_id_not_seen",
        "updated": "content_hash_changed",
        "duplicate": "same_doc_id_and_content_hash",
    }.get(decision, "mixed_document_outcomes")


def _heartbeat(document_id: str, progress: str) -> None:
    try:
        activity.heartbeat({
            "document_id": document_id,
            "progress": progress,
        })
    except RuntimeError:
        # Direct unit tests run activities outside a Temporal activity context.
        return


__all__ = ["classify_source_documents"]
