"""Container-entrypoint scenarios for shared-group permissions and invalid configuration."""

from __future__ import annotations

import os
from pathlib import Path
import stat
import subprocess
import sys


ENTRYPOINT = Path(__file__).parents[3] / "docker" / "python-rag" / "shared-storage-entrypoint.sh"


def test_entrypoint_creates_group_writable_setgid_directories(tmp_path: Path) -> None:
    shared_root = tmp_path / "shared"
    existing_source = shared_root / "sources" / "existing"
    existing_source.mkdir(parents=True, mode=0o755)

    environment = os.environ.copy()
    environment.update(
        {
            "HAWKI_RAG_TEMPORAL_SHARED_ROOT": str(shared_root),
            "HAWKI_RAG_SHARED_STORAGE_INIT": "1",
            "PIPELINE_SHARED_STORAGE_GID": str(os.getgid()),
        }
    )
    create_workspace = (
        "from pathlib import Path; "
        f"root = Path({str(shared_root)!r}); "
        "workspace = root / 'sources' / 'created' / 'raw'; "
        "workspace.mkdir(parents=True); "
        "(workspace / 'page.md').write_text('page', encoding='utf-8')"
    )

    subprocess.run(
        ["sh", str(ENTRYPOINT), sys.executable, "-c", create_workspace],
        check=True,
        env=environment,
    )

    created_workspace = shared_root / "sources" / "created" / "raw"
    created_file = created_workspace / "page.md"

    for directory in (shared_root, shared_root / "sources", existing_source, created_workspace):
        mode = directory.stat().st_mode
        assert directory.stat().st_gid == os.getgid()
        assert mode & stat.S_IWGRP
        assert mode & stat.S_IXGRP
        if sys.platform.startswith("linux"):
            assert mode & stat.S_ISGID

    assert created_file.stat().st_gid == os.getgid()
    assert created_file.stat().st_mode & stat.S_IWGRP


def test_entrypoint_rejects_non_numeric_group_id(tmp_path: Path) -> None:
    shared_root = tmp_path / "shared"
    shared_root.mkdir()

    environment = os.environ.copy()
    environment.update(
        {
            "HAWKI_RAG_TEMPORAL_SHARED_ROOT": str(shared_root),
            "HAWKI_RAG_SHARED_STORAGE_INIT": "1",
            "PIPELINE_SHARED_STORAGE_GID": "www-data",
        }
    )

    result = subprocess.run(
        ["sh", str(ENTRYPOINT), sys.executable, "-c", "raise SystemExit(0)"],
        check=False,
        capture_output=True,
        env=environment,
        text=True,
    )

    assert result.returncode == 64
    assert "must be a numeric group id" in result.stderr


def test_entrypoint_fails_when_shared_storage_was_not_initialized(tmp_path: Path) -> None:
    shared_root = tmp_path / "missing-shared-root"
    environment = os.environ.copy()
    environment["HAWKI_RAG_TEMPORAL_SHARED_ROOT"] = str(shared_root)

    result = subprocess.run(
        ["sh", str(ENTRYPOINT), sys.executable, "-c", "raise SystemExit(0)"],
        check=False,
        capture_output=True,
        env=environment,
        text=True,
    )

    assert result.returncode == 73
    assert "Run the shared-storage init service first" in result.stderr
