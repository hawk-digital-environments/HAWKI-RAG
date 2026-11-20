"""Temporal worker scenario for cleaning converter output before ingestion."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path


class TemporalMarkdownCharacterizationTests(unittest.TestCase):
    """Verify Temporal reads normalized Markdown before handing documents to ingestion."""

    def test_temporal_markdown_reader_strips_converter_noise_before_ingest(
        self,
    ) -> None:
        from hawki_rag_text.markdown import strip_leading_converter_markdown_noise

        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "converted.md"
            path.write_text(
                "\n".join(
                    [
                        "| chunk | Chunk Number,File Name | file |",
                        "| --- | --- | --- |",
                        "| file | Chunk Number | nextChunk |",
                        "",
                        "# Techniker Krankenkasse",
                        "Versicherungsschutz fuer Herrn Yazdan Asadi.",
                    ]
                ),
                encoding="utf-8",
            )

            text = strip_leading_converter_markdown_noise(
                path.read_text(encoding="utf-8")
            )

        self.assertTrue(text.startswith("# Techniker Krankenkasse"))
        self.assertNotIn("nextChunk", text)
