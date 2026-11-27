"""Only a verified terminal, sealed and drained crawler receipt permits retirement."""

from datetime import datetime, timezone

from pydantic import ValidationError
from hawki_rag_contracts.pipeline.crawler import CrawlerReceipt

from .crawler import ExternalCrawlerBoundary
from .errors import UrlIngestionError
from .repository import UrlRepository


class ExternalJobQuiescence:
    def __init__(self, repository: UrlRepository, crawler: ExternalCrawlerBoundary):
        self.repository = repository
        self.crawler = crawler

    async def ensure(self, generation: dict) -> None:
        operation_id = generation["url_ingestion_operation_id"]
        state = self.repository.generation(operation_id)["external_job"]
        wire = generation["workflow_input"]
        job_id = state["job_id"]

        def accept(body):
            receipt = CrawlerReceipt.model_validate(body)
            receipt.verify_owner(wire)
            self.repository.record_external_job(
                operation_id,
                {
                    "job_id": job_id,
                    "external_job_id": receipt.external_job_id,
                    "lifecycle": receipt.status,
                    "sealed": receipt.sealed,
                    "sealed_at": receipt.sealed_at.isoformat()
                    if receipt.sealed_at
                    else None,
                    "quiescent": receipt.quiescent,
                    "writer_admission_closed": receipt.writer_admission_closed,
                    "terminal_evidence": body,
                },
            )
            return receipt.drained

        try:
            if state.get("terminal_evidence") and accept(state["terminal_evidence"]):
                return
        except (ValidationError, ValueError):
            # Old receipts without seal/ownership evidence must be reconciled.
            pass
        scope = {
            key: wire[key]
            for key in (
                "owner",
                "job_id",
                "dataset_id",
                "task_id",
                "source_id",
                "source_url",
                "generation",
                "url_ingestion_operation_id",
                "storage",
                "raw_output_path",
            )
        }
        scope["metadata"] = {
            "scraper_configuration": wire.get("metadata", {}).get(
                "scraper_configuration", {}
            )
        }
        if wire.get("external_services"):
            scope["external_services"] = wire["external_services"]
        try:
            if accept(await self.crawler.run("status", scope)):
                return
            if not state.get("cancel_requested_at"):
                self.repository.record_external_job(
                    operation_id,
                    {
                        "job_id": job_id,
                        "cancel_requested_at": datetime.now(timezone.utc).isoformat(),
                        "cancellation_state": "requested",
                    },
                )
            if accept(await self.crawler.run("cancel", scope)):
                return
            self.repository.record_external_job(
                operation_id,
                {"job_id": job_id, "cancellation_state": "acknowledged"},
            )
        except (UrlIngestionError, ValueError, TypeError):
            self.repository.record_external_job(
                operation_id,
                {
                    "job_id": job_id,
                    "lifecycle": "unknown",
                    "quiescent": False,
                },
            )
        raise UrlIngestionError(
            202,
            "External crawler seal/drainage is unverified; reconciliation is required.",
        )
