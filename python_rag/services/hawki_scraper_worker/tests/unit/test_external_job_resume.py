"""Scraper retry recovery from Temporal heartbeat details."""

from __future__ import annotations

import unittest

from hawki_scraper_worker.activities.scrape import heartbeat_external_job_id


class ScrapeResumeTests(unittest.TestCase):
    def test_scrape_retry_recovers_external_job_id_from_heartbeat(self) -> None:
        job_id = heartbeat_external_job_id(({"external_job_id": "existing-id"},))

        self.assertEqual(job_id, "existing-id")
