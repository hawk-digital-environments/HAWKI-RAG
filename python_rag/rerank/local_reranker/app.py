"""Compatibility shim for the legacy `python_rag.rerank.local_reranker.app` module."""

from pathlib import Path
import sys

_package_root = Path(__file__).resolve().parents[2]
if str(_package_root) not in sys.path:
    sys.path.insert(0, str(_package_root))

from infrastructure.rerank.local_reranker.app import *  # noqa: F401,F403
