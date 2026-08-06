"""Expose callback workspace members before the root environment is synced."""

from __future__ import annotations

from pathlib import Path
import sys


PYTHON_RAG = Path(__file__).resolve().parents[2]
for member in (
    "packages/contracts/src",
    "packages/worker_runtime/src",
):
    sys.path.insert(0, str(PYTHON_RAG / member))
