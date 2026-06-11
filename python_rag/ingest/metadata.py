"""Compatibility shim for legacy module imports."""

from ingest._compat_path import ensure_repo_on_path

ensure_repo_on_path()
from application.cli.ingest.metadata import *  # noqa: F401,F403
