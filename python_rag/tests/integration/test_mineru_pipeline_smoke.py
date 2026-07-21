"""Opt-in end-to-end smoke test for RAWKI's MinerU pipeline backend."""

from __future__ import annotations

import os
from pathlib import Path
import shutil
import subprocess

import pytest


pytestmark = [pytest.mark.integration, pytest.mark.model]


def test_mineru_pipeline_converts_pdf_to_markdown(tmp_path: Path) -> None:
    if os.environ.get("RUN_MINERU_MODEL_TESTS") != "1":
        pytest.skip("set RUN_MINERU_MODEL_TESTS=1 to run the MinerU model smoke test")
    if shutil.which("mineru") is None:
        pytest.skip("MinerU CLI is not installed")

    reportlab_canvas = pytest.importorskip("reportlab.pdfgen.canvas")
    source = tmp_path / "security-smoke.pdf"
    output = tmp_path / "output"
    expected_text = "RAWKI secure MinerU pipeline smoke test"

    canvas = reportlab_canvas.Canvas(str(source))
    canvas.drawString(72, 720, expected_text)
    canvas.save()

    subprocess.run(
        [
            "mineru",
            "-p",
            str(source),
            "-o",
            str(output),
            "-b",
            "pipeline",
        ],
        check=True,
        capture_output=True,
        text=True,
        timeout=600,
    )

    markdown_files = list(output.rglob("*.md"))
    assert markdown_files
    assert any(expected_text in path.read_text(encoding="utf-8") for path in markdown_files)
