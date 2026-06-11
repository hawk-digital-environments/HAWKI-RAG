"""Compatibility adapter for `pipeline.ingest_logic` imports."""

from __future__ import annotations

from pathlib import Path
import sys


def _ensure_python_rag_on_path() -> None:
    package_root = Path(__file__).resolve().parent.parent / "python_rag"
    path_root = str(package_root)
    if path_root not in sys.path:
        sys.path.insert(0, path_root)


_ensure_python_rag_on_path()

from application.workflows.ingest_logic import delete_document, ingest_documents

__all__ = ["delete_document", "ingest_documents"]

