"""Temporal metadata scenarios for UTC persistence and pipeline progress accounting."""

from __future__ import annotations

from types import ModuleType, SimpleNamespace
import sys
import unittest
from unittest.mock import patch

from temporal_rag.metadata import AppMetadataStore


class _FakeConnection:
    def __init__(self) -> None:
        self.closed = False

    def close(self) -> None:
        self.closed = True


class TemporalMetadataTests(unittest.TestCase):
    """Verify workflow metadata uses stable time and page-count semantics."""

    def test_metadata_store_forces_utc_database_session(self) -> None:
        calls: list[dict[str, object]] = []
        connection = _FakeConnection()
        psycopg = ModuleType("psycopg")

        def connect(**kwargs: object) -> _FakeConnection:
            calls.append(kwargs)
            return connection

        psycopg.connect = connect  # type: ignore[attr-defined]
        previous = sys.modules.get("psycopg")
        sys.modules["psycopg"] = psycopg
        try:
            store = AppMetadataStore(
                SimpleNamespace(
                    db_host="postgres",
                    db_port=5432,
                    db_name="hawki_rag",
                    db_user="rag_user",
                    db_password="secret",
                )
            )

            with store.connection() as returned:
                self.assertIs(returned, connection)
        finally:
            if previous is None:
                sys.modules.pop("psycopg", None)
            else:
                sys.modules["psycopg"] = previous

        self.assertEqual(calls[0]["options"], "-c timezone=UTC")
        self.assertTrue(connection.closed)

    def test_scrape_stage_counts_use_profile_page_limit_as_total_pages(self) -> None:
        counts = AppMetadataStore._stage_counts(
            "scrape",
            {
                "pages_crawled": 4,
                "files_found": 4,
                "max_pages": 300,
            },
        )

        self.assertEqual(counts["processed"], 4)
        self.assertEqual(counts["pagesCrawled"], 4)
        self.assertEqual(counts["total"], 300)
        self.assertEqual(counts["totalPages"], 300)
        self.assertEqual(counts["pageLimit"], 300)

    def test_mark_ready_propagates_database_failure_for_temporal_retry(self) -> None:
        store = AppMetadataStore(SimpleNamespace())

        with patch.object(store, "connection", side_effect=ConnectionError("database offline")):
            with self.assertRaisesRegex(ConnectionError, "database offline"):
                store.mark_ready(
                    {"source_id": "source-a", "job_id": "job-a"},
                    {"document_version": "v1"},
                )


if __name__ == "__main__":
    unittest.main()
