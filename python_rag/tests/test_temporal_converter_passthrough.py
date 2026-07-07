from __future__ import annotations

import json
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
from types import SimpleNamespace
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

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
                _settings(),
            )

        self.assertEqual(config["base_url"], "https://converter.example.test")
        self.assertEqual(config["start_path"], "/extract")
        self.assertEqual(config["status_path"], "/jobs/{job_id}")
        self.assertEqual(config["token"], "secret-token")

    def test_custom_converter_mode_requires_profile_path(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "without a converter profile path"):
            activities._service_config({"converter_mode": "custom"}, _settings())

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

    def test_direct_extract_skips_raw_directory_bookkeeping_files(self) -> None:
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            raw_dir = root / "raw"
            markdown_dir = root / "markdown"
            raw_dir.mkdir()
            for name in activities.RAW_DIRECTORY_BOOKKEEPING_FILENAMES:
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
                object(),
                {"source_id": "source_image", "job_id": "job_image"},
                {"graph": False},
                docs,
            )

        body = bridge.call_args.kwargs["json"]
        headers = bridge.call_args.kwargs["headers"]
        self.assertIs(body["graph"], True)
        self.assertEqual(body["idempotency_key"], "source_image:job_image:doc_image:ingest")
        self.assertEqual(headers["Idempotency-Key"], "source_image:job_image:doc_image:ingest")
        self.assertEqual(headers["X-Request-ID"], "source_image:job_image:doc_image:ingest")


def _settings() -> SimpleNamespace:
    return SimpleNamespace(
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
