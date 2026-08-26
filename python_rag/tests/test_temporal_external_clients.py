from __future__ import annotations

import unittest
from unittest.mock import Mock

from hawki_scraper_worker.activities.scrape import heartbeat_external_job_id
from hawki_external_jobs import ExternalJobClient


class ExternalJobClientTests(unittest.TestCase):
    def test_scrape_retry_recovers_external_job_id_from_heartbeat(self) -> None:
        job_id = heartbeat_external_job_id(({"external_job_id": "existing-id"},))

        self.assertEqual(job_id, "existing-id")

    def test_resume_polls_existing_job_without_submitting_duplicate(self) -> None:
        client = ExternalJobClient(
            base_url="http://scraper.test",
            start_path="/start",
            status_path="/jobs/{job_id}",
        )
        client._request = Mock(return_value={"status": "completed"})  # type: ignore[method-assign]
        heartbeats: list[str] = []

        result = client.start_and_wait(
            {"job_id": "same-id"},
            resume_job_id="same-id",
            progress_callback=heartbeats.append,
        )

        client._request.assert_called_once_with("GET", "jobs/same-id")
        self.assertEqual(result["external_job_id"], "same-id")
        self.assertEqual(result["status"], "completed")
        self.assertEqual(heartbeats, ["same-id", "same-id"])

    def test_new_job_is_recorded_before_status_polling(self) -> None:
        client = ExternalJobClient(
            base_url="http://scraper.test",
            start_path="/start",
            status_path="/jobs/{job_id}",
        )
        client._request = Mock(  # type: ignore[method-assign]
            side_effect=[
                {"job_id": "new-id", "status": "queued"},
                {"status": "completed"},
            ]
        )
        heartbeats: list[str] = []

        result = client.start_and_wait(
            {"url": "https://example.test"}, progress_callback=heartbeats.append
        )

        self.assertEqual(client._request.call_args_list[0].args, ("POST", "start"))
        self.assertEqual(client._request.call_args_list[1].args, ("GET", "jobs/new-id"))
        self.assertEqual(result["external_job_id"], "new-id")
        self.assertEqual(heartbeats, ["new-id", "new-id"])


if __name__ == "__main__":
    unittest.main()
