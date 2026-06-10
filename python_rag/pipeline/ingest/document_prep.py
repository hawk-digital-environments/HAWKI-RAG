"""Backward-compatible wrapper around :mod:`pipeline.ingest.chunking`."""
from __future__ import annotations

from pipeline.ingest.chunking import (
    doc_job_id,
    prepare_documents,
)

__all__ = ["doc_job_id", "prepare_documents"]
