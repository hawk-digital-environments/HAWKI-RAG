"""Tests for RAWKI's checksum-guarded MinerU compatibility wheel."""

from __future__ import annotations

import base64
import csv
from hashlib import sha256
import io
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

import pytest

from scripts import build_mineru_transformers5_wheel as builder


def _write_minimal_upstream_wheel(path: Path) -> None:
    source = "".join(patch.before for patch in builder.SOURCE_PATCHES)
    unimer_source = "".join(patch.before for patch in builder.UNIMER_SOURCE_PATCHES)
    metadata = "".join(
        (
            "Metadata-Version: 2.4\n",
            "Name: mineru\n",
            builder.VERSION_PATCH.before,
            'Requires-Dist: transformers<5.0.0,>=4.57.3; extra == "vlm"\n',
            f"{builder.TRANSFORMERS_REQUIREMENT_PATCH.before}\n",
            builder.CORE_VLM_REQUIREMENT_PATCH.before,
            'Requires-Dist: mineru[pipeline]; extra == "core"\n',
            builder.CORE_GRADIO_REQUIREMENT_PATCH.before,
        )
    )

    with ZipFile(path, "w", compression=ZIP_DEFLATED) as archive:
        archive.writestr(builder.SOURCE_PATH.as_posix(), source)
        archive.writestr(builder.UNIMER_SOURCE_PATH.as_posix(), unimer_source)
        archive.writestr(
            builder.VERSION_MODULE_PATH.as_posix(),
            builder.VERSION_MODULE_PATCH.before,
        )
        archive.writestr(f"{builder.UPSTREAM_DIST_INFO}/METADATA", metadata)
        archive.writestr(
            f"{builder.UPSTREAM_DIST_INFO}/WHEEL",
            "Wheel-Version: 1.0\nRoot-Is-Purelib: true\nTag: py3-none-any\n",
        )
        archive.writestr(f"{builder.UPSTREAM_DIST_INFO}/RECORD", "")


def _assert_record_hashes(archive: ZipFile) -> None:
    record_path = f"{builder.PATCHED_DIST_INFO}/RECORD"
    rows = csv.reader(io.StringIO(archive.read(record_path).decode("utf-8")))

    for relative_path, digest_field, size_field in rows:
        if relative_path == record_path:
            assert digest_field == ""
            assert size_field == ""
            continue

        data = archive.read(relative_path)
        digest = base64.urlsafe_b64encode(sha256(data).digest()).rstrip(b"=").decode("ascii")
        assert digest_field == f"sha256={digest}"
        assert size_field == str(len(data))


def test_builder_rejects_unknown_source_drift() -> None:
    with pytest.raises(RuntimeError, match="Refusing to patch an unknown artifact"):
        builder.patch_source("unexpected source")


def test_builder_emits_deterministic_pipeline_only_wheel(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    upstream_wheel = tmp_path / builder.UPSTREAM_WHEEL_NAME
    _write_minimal_upstream_wheel(upstream_wheel)
    monkeypatch.setattr(
        builder,
        "UPSTREAM_WHEEL_SHA256",
        sha256(upstream_wheel.read_bytes()).hexdigest(),
    )

    first = builder.build_compatibility_wheel(upstream_wheel, tmp_path / "first")
    second = builder.build_compatibility_wheel(upstream_wheel, tmp_path / "second")

    assert first.read_bytes() == second.read_bytes()
    with ZipFile(first) as archive:
        names = set(archive.namelist())
        assert f"{builder.UPSTREAM_DIST_INFO}/METADATA" not in names
        assert f"{builder.PATCHED_DIST_INFO}/METADATA" in names

        source = archive.read(builder.SOURCE_PATH.as_posix()).decode("utf-8")
        for patch in builder.SOURCE_PATCHES:
            assert patch.before not in source
            assert patch.after in source

        unimer_source = archive.read(builder.UNIMER_SOURCE_PATH.as_posix()).decode("utf-8")
        for patch in builder.UNIMER_SOURCE_PATCHES:
            assert patch.before not in unimer_source
            assert patch.after in unimer_source

        version_module = archive.read(builder.VERSION_MODULE_PATH.as_posix()).decode("utf-8")
        assert version_module == builder.VERSION_MODULE_PATCH.after

        metadata = archive.read(f"{builder.PATCHED_DIST_INFO}/METADATA").decode("utf-8")
        assert builder.VERSION_PATCH.after in metadata
        assert builder.TRANSFORMERS_REQUIREMENT_PATCH.after in metadata
        assert 'transformers<5.0.0,>=4.57.3; extra == "vlm"' in metadata
        assert 'mineru[pipeline]; extra == "core"' in metadata
        assert 'mineru[vlm]; extra == "core"' not in metadata
        assert 'mineru[gradio]; extra == "core"' not in metadata
        _assert_record_hashes(archive)
