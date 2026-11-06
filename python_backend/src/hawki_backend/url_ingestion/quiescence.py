"""Remote writers must attest drainage; Temporal closure is insufficient.

The current CustomCrawler exposes status and cancellation but does not attest
quiescence: it publishes terminal status before its finally block has drained.
A terminal status without an explicit drained-job receipt therefore stays pending.
"""

from datetime import datetime, timezone
from urllib.parse import quote

import httpx

from .errors import UrlIngestionError
from .repository import UrlRepository

TERMINAL = frozenset({"completed", "failed", "cancelled"})


class ExternalJobQuiescence:
    def __init__(self, repository: UrlRepository, *, client_factory=httpx.Client):
        self.repository = repository
        self.client_factory = client_factory

    def ensure(self, generation: dict) -> None:
        state = self.repository.generation(generation["url_ingestion_operation_id"])[
            "external_job"
        ]
        if (
            state.get("quiescent") is True
            and state.get("lifecycle") in TERMINAL
            and state.get("writer_admission_closed") is True
        ):
            return
        operation_id = generation["url_ingestion_operation_id"]
        job_id = state["job_id"]
        services = generation["workflow_input"]["external_services"]
        base = services["scraper_url"]
        headers = (
            {"Authorization": "Bearer " + services["scraper_token"]}
            if services.get("scraper_token")
            else {}
        )
        # The URL is frozen server configuration, never a source URL or callback
        # supplied address. Do not follow redirects or ambient proxy settings.
        try:
            with self.client_factory(
                timeout=5, follow_redirects=False, trust_env=False, headers=headers
            ) as client:
                response = client.get(
                    base.rstrip("/") + "/status/" + quote(job_id, safe="")
                )
                response.raise_for_status()
                body = response.json()
                if not isinstance(body, dict) or body.get("job_id") != job_id:
                    raise ValueError("External job receipt differs")
                status = body.get("status")
                if (
                    status in TERMINAL
                    and body.get("quiescent") is True
                    and body.get("writer_admission_closed") is True
                ):
                    self.repository.record_external_job(
                        operation_id,
                        {
                            "job_id": job_id,
                            "lifecycle": status,
                            "quiescent": True,
                            "writer_admission_closed": True,
                            "terminal_evidence": body,
                        },
                    )
                    return
                self.repository.record_external_job(
                    operation_id,
                    {
                        "job_id": job_id,
                        "lifecycle": status if isinstance(status, str) else "unknown",
                        "quiescent": False,
                    },
                )
                if status not in TERMINAL and not state.get("cancel_requested_at"):
                    # Commit intent before the RPC; a lost response resumes the
                    # same job. Repeating cancellation is safe, never a new start.
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
                if status not in TERMINAL:
                    cancelled = client.post(
                        base.rstrip("/") + "/jobs/" + quote(job_id, safe="") + "/cancel"
                    )
                    cancelled.raise_for_status()
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
            "External crawler quiescence is unverified; reconciliation is required.",
        )
