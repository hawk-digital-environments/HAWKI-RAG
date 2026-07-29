"""Temporal conversion scenarios from crawler output through custom and passthrough conversion."""

from __future__ import annotations

import json
from pathlib import Path
from tempfile import TemporaryDirectory
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from temporal_rag import activities


class _UnsupportedResponse:
    status_code = 400
    ok = False
    content = b""
    text = '{"detail":"Unsupported file type. Supported types: .pdf, .doc, .docx"}'

    def json(self) -> dict[str, str]:
        return {"detail": "Unsupported file type. Supported types: .pdf, .doc, .docx"}

    def raise_for_status(self) -> None:
        raise RuntimeError("400 Client Error")


class TemporalConverterPassthroughTests(unittest.TestCase):
    """Verify worker payloads, converter profiles, and unsupported-file passthrough behavior."""

    def test_scraper_start_payload_matches_custom_crawler_contract(self) -> None:
        payload = activities._scraper_start_payload(
            {
                "job_id": "ingest_lubeck",
                "source_url": "https://uni-luebeck.de",
                "metadata": {
                    "request": {
                        "sitemapUrl": "https://uni-luebeck.de/sitemap.xml",
                        "metadata": {
                            "site_profile_path": "/var/www/html/profiles/Lubeck.json",
                            "max_pages": 25,
                            "max_concurrency": 1,
                            "max_rpm": 60,
                            "skip_images": True,
                            "discovery_mode": False,
                        },
                    },
                },
            },
            "source_lubeck",
            "/shared/sources/source_lubeck/raw/",
        )

        self.assertEqual(payload["job_id"], "ingest_lubeck")
        self.assertEqual(payload["url"], "https://uni-luebeck.de")
        self.assertEqual(payload["output_dir"], "/shared/sources/source_lubeck/raw/")
        self.assertEqual(payload["source_id"], "source_lubeck")
        self.assertEqual(payload["source_url"], "https://uni-luebeck.de")
        self.assertEqual(payload["site_profile_path"], "/var/www/html/profiles/Lubeck.json")
        self.assertIs(payload["sitemap"], True)
        self.assertEqual(payload["sitemap_base"], "https://uni-luebeck.de/sitemap.xml")
        self.assertEqual(payload["max_pages"], 25)
        self.assertEqual(payload["max_concurrency"], 1)
        self.assertEqual(payload["max_rpm"], 60)
        self.assertIs(payload["skip_images"], True)
        self.assertIs(payload["discovery_mode"], False)

    def test_crawler_output_directory_is_mapped_to_worker_shared_path(self) -> None:
        self.assertEqual(
            activities._shared_worker_path("/var/www/html/shared/ingest_lubeck"),
            "/shared/ingest_lubeck",
        )
        self.assertEqual(
            activities._shared_worker_path("/app/shared/sources/source_lubeck/raw"),
            "/shared/sources/source_lubeck/raw",
        )
        self.assertEqual(
            activities._shared_worker_path("/shared/sources/source_lubeck/raw"),
            "/shared/sources/source_lubeck/raw",
        )

    def test_scrape_result_fails_when_scraper_only_writes_bookkeeping_files(self) -> None:
        with TemporaryDirectory() as tmp:
            raw_dir = Path(tmp)
            (raw_dir / "job_state.json").write_text('{"completed_urls": 0}', encoding="utf-8")
            (raw_dir / "urls_index.json").write_text('{"total_entries": 0}', encoding="utf-8")
            (raw_dir / "summary.json").write_text('{"status": "completed"}', encoding="utf-8")
            (raw_dir / "crawler.log").write_text("completed\n", encoding="utf-8")

            result = activities._scrape_result(
                {
                    "status": "completed",
                    "output_directory": str(raw_dir),
                    "external_job_id": "scrape-empty",
                },
                {"max_pages": 300},
                "source_empty",
                str(raw_dir),
            )

        self.assertEqual(result["status"], "failed")
        self.assertEqual(result["pages_crawled"], 0)
        self.assertEqual(result["files_found"], 0)
        self.assertEqual(result["raw_files_found"], 0)
        self.assertEqual(result["max_pages"], 300)
        self.assertIn("without crawled page files", result["error_details"])

    def test_scrape_result_uses_real_output_files_when_scraper_omits_counts(self) -> None:
        with TemporaryDirectory() as tmp:
            raw_dir = Path(tmp)
            (raw_dir / "job_state.json").write_text('{"completed_urls": 1}', encoding="utf-8")
            page = raw_dir / "pages" / "home.md"
            page.parent.mkdir()
            page.write_text("# Home", encoding="utf-8")

            result = activities._scrape_result(
                {
                    "status": "completed",
                    "output_directory": str(raw_dir),
                    "external_job_id": "scrape-one-page",
                },
                {"max_pages": 1},
                "source_page",
                str(raw_dir),
            )

        self.assertEqual(result["status"], "success")
        self.assertEqual(result["pages_crawled"], 1)
        self.assertEqual(result["files_found"], 1)
        self.assertEqual(result["raw_files_found"], 1)
        self.assertEqual(result["max_pages"], 1)

    def test_scrape_result_fails_when_scraper_stops_before_page_limit(self) -> None:
        with TemporaryDirectory() as tmp:
            raw_dir = Path(tmp)
            page = raw_dir / "pages" / "home.md"
            page.parent.mkdir()
            page.write_text("# Home", encoding="utf-8")

            result = activities._scrape_result(
                {
                    "status": "completed",
                    "output_directory": str(raw_dir),
                    "external_job_id": "scrape-short",
                },
                {"max_pages": 300},
                "source_short",
                str(raw_dir),
            )

        self.assertEqual(result["status"], "failed")
        self.assertEqual(result["pages_crawled"], 1)
        self.assertEqual(result["files_found"], 1)
        self.assertEqual(result["raw_files_found"], 1)
        self.assertEqual(result["max_pages"], 300)
        self.assertIn("1/300 pages", result["error_details"])

    def test_custom_converter_profile_overrides_converter_service_config(self) -> None:
        with TemporaryDirectory() as tmp:
            profile = Path(tmp) / "custom_converter.json"
            profile.write_text(
                json.dumps({
                    "converter_url": "https://converter.example.test",
                    "converter_start_path": "/extract",
                    "converter_status_path": "/jobs/{job_id}",
                    "converter_token": "secret-token",
                }),
                encoding="utf-8",
            )

            config = activities._service_config(
                {
                    "converter_mode": "custom",
                    "custom_converter_profile_path": str(profile),
                    "external_services": {
                        "converter_url": "http://default-converter.test",
                        "converter_start_path": "/extract",
                    },
                },
                "converter",
                _settings(),
            )

        self.assertEqual(config["base_url"], "https://converter.example.test")
        self.assertEqual(config["start_path"], "/extract")
        self.assertEqual(config["status_path"], "/jobs/{job_id}")
        self.assertEqual(config["token"], "secret-token")

    def test_custom_converter_mode_requires_profile_path(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "without a converter profile path"):
            activities._service_config(
                {"converter_mode": "custom"},
                "converter",
                _settings(),
            )

    def test_direct_extract_unsupported_image_creates_raganything_passthrough(self) -> None:
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            raw_dir = root / "raw"
            markdown_dir = root / "markdown"
            raw_dir.mkdir()
            image = raw_dir / "pxl-20211207-184833463-svtbrbu9.jpg"
            image.write_bytes(b"fake jpeg bytes")

            config = {
                "base_url": "http://converter.test",
                "start_path": "/extract",
                "token": "file-converter-key",
                "timeout_seconds": 1,
                "retry_attempts": 3,
            }

            with patch("temporal_rag.activities.requests.post", return_value=_UnsupportedResponse()) as post:
                result = activities._convert_files_with_extract_api(
                    config,
                    "source_image",
                    str(raw_dir),
                    str(markdown_dir),
                )

            self.assertEqual(post.call_count, 1)
            self.assertEqual(result["status"], "success")
            self.assertEqual(result["markdown_files_created"], 1)
            self.assertEqual(result["passthrough_files"], [str(image)])

            handoff = next(markdown_dir.rglob("content_markdown.md"))
            self.assertIn("RAG-Anything/MinerU", handoff.read_text(encoding="utf-8"))

            metadata_path = handoff.parent / activities.PASSTHROUGH_METADATA_FILENAME
            metadata = json.loads(metadata_path.read_text(encoding="utf-8"))
            self.assertEqual(metadata["source_format"], "raganything_passthrough")
            self.assertEqual(metadata["original_filename"], image.name)
            self.assertEqual(metadata["file_path"], str(image.resolve()))
            self.assertEqual(metadata["image_path"], str(image.resolve()))
            self.assertEqual(metadata["images"], [str(image.resolve())])

            loaded = activities._load_passthrough_metadata(str(handoff))
            self.assertEqual(loaded["file_path"], str(image.resolve()))

    def test_direct_extract_skips_scraper_bookkeeping_files(self) -> None:
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            raw_dir = root / "raw"
            markdown_dir = root / "markdown"
            raw_dir.mkdir()
            for name in activities.SCRAPER_BOOKKEEPING_FILENAMES:
                (raw_dir / name).write_text("{}", encoding="utf-8")

            result = activities._convert_files_with_extract_api(
                {
                    "base_url": "http://converter.test",
                    "start_path": "/extract",
                    "timeout_seconds": 1,
                    "retry_attempts": 1,
                },
                "source_empty",
                str(raw_dir),
                str(markdown_dir),
            )

        self.assertEqual(result["status"], "failed")
        self.assertEqual(result["converted_files"], [])
        self.assertEqual(result["markdown_files_created"], 0)
        self.assertIn("No files were found", result["error_details"])

    def test_passthrough_documents_force_graph_ingestion(self) -> None:
        docs = [{
            "id": "doc_image",
            "text": "Image handoff",
            "payload": {"converter_fallback": "raganything_passthrough"},
        }]

        with patch("temporal_rag.activities._bridge_request", return_value={"ok": True}) as bridge:
            activities._post_ingest(
                object(),  # _bridge_request is mocked, so no settings fields are read.
                {"source_id": "source_image", "job_id": "job_image", "dataset_id": "dataset-image"},
                {"graph": False, "neo4j_namespace": "hawki_dataset_image"},
                docs,
            )

        body = bridge.call_args.kwargs["json"]
        headers = bridge.call_args.kwargs["headers"]
        self.assertIs(body["graph"], True)
        self.assertEqual(body["dataset_id"], "dataset-image")
        self.assertEqual(body["neo4j_namespace"], "hawki_dataset_image")
        self.assertIsNone(body["neo4j_database"])
        self.assertEqual(body["idempotency_key"], "source_image:job_image:doc_image:ingest")
        self.assertEqual(headers["Idempotency-Key"], "source_image:job_image:doc_image:ingest")
        self.assertEqual(headers["X-Request-ID"], "source_image:job_image:doc_image:ingest")


def _settings() -> SimpleNamespace:
    return SimpleNamespace(
        scraper_url="http://scraper.test",
        scraper_start_path="/api/scrape/start",
        scraper_status_path="/api/scrape/jobs/{job_id}",
        scraper_token="",
        converter_url="http://converter.test",
        converter_start_path="/extract",
        converter_status_path="",
        converter_token="",
        request_timeout_seconds=1,
        http_retry_attempts=1,
        poll_interval_seconds=1,
        poll_timeout_seconds=2,
    )


if __name__ == "__main__":
    unittest.main()
