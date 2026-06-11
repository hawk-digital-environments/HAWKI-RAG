"""Backward-compatible compatibility layer for the historic `python_rag.ingest` namespace.

The canonical CLI implementation now lives under `application.cli.ingest`.
Existing imports such as `import ingest.*` continue to work through these shims.
"""

from ingest._compat_path import ensure_repo_on_path

ensure_repo_on_path()

from application.cli.ingest import discovery as discovery
from application.cli.ingest import estimation as estimation
from application.cli.ingest import links as links
from application.cli.ingest import materials as materials
from application.cli.ingest import metadata as metadata
from application.cli.ingest import payloads as payloads
from application.cli.ingest import prune_missing_docs as prune_missing_docs
from application.cli.ingest import resume as resume
from application.cli.ingest import retry_ingest_docs as retry_ingest_docs
from application.cli.ingest import runner as runner
from application.cli.ingest import submit as submit
from application.cli.ingest import url_maps as url_maps
from application.cli.ingest.ingest_crawled import ingest_crawled

__all__ = [
    "discovery",
    "estimation",
    "ingest_crawled",
    "links",
    "materials",
    "metadata",
    "payloads",
    "prune_missing_docs",
    "resume",
    "retry_ingest_docs",
    "runner",
    "submit",
    "url_maps",
]
