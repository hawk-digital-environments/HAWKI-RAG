"""Idempotent CustomCrawler adapter; legacy external job clients stay unchanged."""

import time

from hawki_rag_contracts.pipeline.crawler import CrawlerReceipt, require_crawler_api

from .client import ExternalJobClient


class CustomCrawlerClient(ExternalJobClient):
    def __init__(self, *, workflow_input: dict, **kwargs):
        super().__init__(**kwargs)
        self.workflow_input = workflow_input
        self.session.trust_env = False
        self.strict_http = True

    def _request(self, method, path, **kwargs):
        return super()._request(method, path, allow_redirects=False, **kwargs)

    def require_capability(self) -> None:
        require_crawler_api(self._request("GET", "/openapi.json"))
        if self._request("GET", "/health").get("status") != "healthy":
            raise RuntimeError("CustomCrawler is unavailable")

    def start_and_wait(self, payload, *, resume_job_id=None, progress_callback=None):
        self.require_capability()
        job_id = payload["job_id"]
        if resume_job_id not in {None, job_id}:
            raise RuntimeError("Crawler retry identity differs from its generation")
        # The identity is committed by the URL owner before dispatch. Even when
        # POST delivery is ambiguous, retries send the exact same permanent key.
        if progress_callback:
            progress_callback(job_id)
        started = self._request("POST", self.start_path, json=payload)
        if (
            started.get("job_id") != job_id
            or started.get("event") not in {"job_submitted", "job_exists"}
            or not isinstance(started.get("data"), dict)
            or not isinstance(started["data"].get("sealed"), bool)
        ):
            raise RuntimeError("Incompatible crawler admission receipt")
        deadline = time.monotonic() + self.poll_timeout_seconds
        while time.monotonic() < deadline:
            snapshot = self._request("GET", self.status_path.format(job_id=job_id))
            receipt = CrawlerReceipt.model_validate(snapshot)
            receipt.verify_owner(self.workflow_input)
            if progress_callback:
                progress_callback(job_id)
            if receipt.drained:
                return snapshot
            if receipt.status == "unknown":
                raise RuntimeError("Crawler drainage requires reconciliation")
            time.sleep(self.poll_interval_seconds)
        raise RuntimeError("Crawler terminal/sealed receipt is unconfirmed")
