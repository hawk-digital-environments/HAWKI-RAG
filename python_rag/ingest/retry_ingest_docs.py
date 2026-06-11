"""Backward-compatible entrypoint shim for legacy script path."""

from ingest._compat_path import ensure_repo_on_path

ensure_repo_on_path()
from application.cli.ingest.retry_ingest_docs import *  # noqa: F401,F403


if __name__ == "__main__":
    raise SystemExit(main())
