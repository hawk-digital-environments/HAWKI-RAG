"""Expose scraper workspace members before the root environment is synced."""

from __future__ import annotations

from pathlib import Path
import sys


PYTHON_RAG = Path(__file__).resolve().parents[2]
for member in (
    "packages/contracts/src",
    "packages/text_processing/src",
    "packages/artifact_store/src",
    "packages/observability/src",
    "packages/pipeline_callbacks/src",
    "packages/external_jobs/src",
    "packages/worker_runtime/src",
    "services/hawki_scraper_worker/src",
):
    sys.path.insert(0, str(PYTHON_RAG / member))
