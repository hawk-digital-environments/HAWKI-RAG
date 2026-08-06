"""Expose the two uv workspace members used by workflow unit tests."""

from __future__ import annotations

from pathlib import Path
import sys


PYTHON_RAG = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(PYTHON_RAG / "packages" / "contracts" / "src"))
sys.path.insert(0, str(PYTHON_RAG / "services" / "hawki_workflow_worker" / "src"))
