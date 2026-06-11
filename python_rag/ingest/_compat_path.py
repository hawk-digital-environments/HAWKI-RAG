"""Compatibility helpers for legacy `python_rag.ingest` shims."""

from __future__ import annotations

import sys
from pathlib import Path


def ensure_repo_on_path() -> None:
    """Ensure the repository's Python package root is importable."""
    repo_root = Path(__file__).resolve().parent.parent
    if str(repo_root) not in sys.path:
        sys.path.insert(0, str(repo_root))
