from __future__ import annotations

import json
from pathlib import Path
from tempfile import TemporaryDirectory
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

    def test_passthrough_documents_force_graph_ingestion(self) -> None:
        docs = [{
            "id": "doc_image",
            "text": "Image handoff",
            "payload": {"converter_fallback": "raganything_passthrough"},
        }]

        with patch("temporal_rag.activities._bridge_request", return_value={"ok": True}) as bridge:
            activities._post_ingest(
                object(),  # _bridge_request is mocked, so no settings fields are read.
                {"source_id": "source_image", "job_id": "job_image"},
                {"graph": False},
                docs,
            )

        body = bridge.call_args.kwargs["json"]
        self.assertIs(body["graph"], True)


if __name__ == "__main__":
    unittest.main()
