"""Only a verified terminal, sealed and drained crawler receipt permits retirement."""

from datetime import datetime, timezone
from urllib.parse import quote

import httpx
from pydantic import ValidationError
from hawki_rag_contracts.pipeline.crawler import CrawlerReceipt

from .errors import UrlIngestionError
from .repository import UrlRepository


class ExternalJobQuiescence:
    def __init__(self, repository: UrlRepository, *, client_factory=httpx.Client):
        self.repository = repository
        self.client_factory = client_factory

    def ensure(self, generation: dict) -> None:
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
        services = wire["external_services"]
        base = services["scraper_url"].rstrip("/")
        headers = (
            {"Authorization": "Bearer " + services["scraper_token"]}
            if services.get("scraper_token")
            else {}
        )
        try:
            with self.client_factory(
                timeout=5, follow_redirects=False, trust_env=False, headers=headers
            ) as client:
                response = client.get(base + "/status/" + quote(job_id, safe=""))
                response.raise_for_status()
                if accept(response.json()):
                    return
                if not state.get("cancel_requested_at"):
                    self.repository.record_external_job(
                        operation_id,
                        {
                            "job_id": job_id,
                            "cancel_requested_at": datetime.now(
                                timezone.utc
                            ).isoformat(),
                            "cancellation_state": "requested",
                        },
                    )
                cancelled = client.post(
                    base + "/jobs/" + quote(job_id, safe="") + "/cancel"
                )
                cancelled.raise_for_status()
                # The cancellation endpoint returns the durable receipt, not
                # permission to clean merely because cancellation was accepted.
                if accept(cancelled.json()):
                    return
                self.repository.record_external_job(
                    operation_id,
                    {
                        "job_id": job_id,
                        "cancellation_state": "acknowledged",
                    },
                )
        except (httpx.HTTPError, ValueError, TypeError):
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
