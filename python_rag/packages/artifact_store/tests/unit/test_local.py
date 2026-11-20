"""Behavioral contracts for the rooted local artifact store."""

from __future__ import annotations

import json
import os
from pathlib import Path

import pytest

from hawki_artifact_store.local import LocalArtifactStore


@pytest.fixture
def shared_root(tmp_path: Path) -> Path:
    root = tmp_path / "shared"
    root.mkdir()
    return root


@pytest.fixture
def store(shared_root: Path) -> LocalArtifactStore:
    return LocalArtifactStore(shared_root)


def test_resolve_accepts_absolute_paths_and_local_file_uris(
    store: LocalArtifactStore,
    shared_root: Path,
) -> None:
    artifact = shared_root / "sources" / "source-a" / "document.md"
    artifact.parent.mkdir(parents=True)
    artifact.write_text("content", encoding="utf-8")

    assert store.resolve(str(artifact)) == artifact.resolve()
    assert store.resolve(artifact.as_uri()) == artifact.resolve()


def test_resolve_rejects_path_traversal_outside_shared_root(
    store: LocalArtifactStore,
    shared_root: Path,
    tmp_path: Path,
) -> None:
    outside = tmp_path / "outside"
    outside.mkdir()
    traversal = shared_root / ".." / outside.name / "secret.md"

    with pytest.raises(ValueError):
        store.resolve(str(traversal))


def test_recreate_directory_rejects_symlink_escape_without_deleting_target(
    store: LocalArtifactStore,
    shared_root: Path,
    tmp_path: Path,
) -> None:
    outside = tmp_path / "outside"
    outside.mkdir()
    marker = outside / "keep.txt"
    marker.write_text("must survive", encoding="utf-8")
    escape = shared_root / "escape"
    escape.symlink_to(outside, target_is_directory=True)

    with pytest.raises(ValueError):
        store.recreate_directory(str(escape))

    assert marker.read_text(encoding="utf-8") == "must survive"


@pytest.mark.parametrize(
    "location",
    [
        "s3://bucket/document.md",
        "gs://bucket/document.md",
        "https://example.test/document.md",
        "file://worker/shared/document.md",
    ],
)
def test_resolve_rejects_remote_uri_schemes_and_file_hosts(
    store: LocalArtifactStore,
    location: str,
) -> None:
    with pytest.raises(ValueError):
        store.resolve(location)


def test_list_markdown_returns_only_markdown_files_in_sorted_order(
    store: LocalArtifactStore,
    shared_root: Path,
) -> None:
    documents = shared_root / "documents"
    nested = documents / "nested"
    nested.mkdir(parents=True)

    expected_paths = [
        documents / "a.markdown",
        nested / "b.MD",
        documents / "z.md",
    ]
    for path in expected_paths:
        path.write_text(path.name, encoding="utf-8")
    (documents / "ignored.txt").write_text("not Markdown", encoding="utf-8")

    expected = [str(path.resolve()) for path in sorted(expected_paths)]
    assert store.list_markdown(str(documents)) == expected


def test_read_text_returns_exact_utf8_content_without_normalization(
    store: LocalArtifactStore,
    shared_root: Path,
) -> None:
    artifact = shared_root / "converted.md"
    content = (
        "| chunk | Chunk Number,File Name | file |\n"
        "| --- | --- | --- |\n"
        "\n"
        "# Gr\u00fc\u00dfe aus K\u00f6ln \u2615\n"
        "  trailing whitespace is content  \n"
    )
    artifact.write_text(content, encoding="utf-8")

    assert store.read_text(str(artifact)) == content


def test_manifest_write_is_deterministic_and_atomically_replaces_existing_file(
    store: LocalArtifactStore,
    shared_root: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    manifest = shared_root / "manifests" / "source-a.json"
    store.write_manifest(str(manifest), [{"version": 1}])
    previous_content = manifest.read_text(encoding="utf-8")

    records = [{"z": 3, "a": 1}, {"b": 2, "a": 0}]
    expected_content = json.dumps(records, indent=2, sort_keys=True) + "\n"
    real_replace = os.replace
    replacements: list[tuple[Path, Path]] = []

    def checked_replace(
        source: str | os.PathLike[str], target: str | os.PathLike[str]
    ) -> None:
        source_path = Path(source)
        target_path = Path(target)

        assert target_path == manifest
        assert target_path.read_text(encoding="utf-8") == previous_content
        assert source_path.parent == target_path.parent
        assert source_path != target_path
        assert source_path.read_text(encoding="utf-8") == expected_content
        replacements.append((source_path, target_path))
        real_replace(source_path, target_path)

    monkeypatch.setattr(os, "replace", checked_replace)

    store.write_manifest(str(manifest), records)

    assert len(replacements) == 1
    assert manifest.read_text(encoding="utf-8") == expected_content
    assert list(manifest.parent.iterdir()) == [manifest]


def test_manifest_write_rejects_escape_without_overwriting_outside_file(
    store: LocalArtifactStore,
    shared_root: Path,
    tmp_path: Path,
) -> None:
    outside = tmp_path / "outside"
    outside.mkdir()
    outside_manifest = outside / "manifest.json"
    outside_manifest.write_text("sentinel\n", encoding="utf-8")
    traversal = shared_root / ".." / outside.name / outside_manifest.name

    with pytest.raises(ValueError):
        store.write_manifest(str(traversal), [{"unsafe": True}])

    assert outside_manifest.read_text(encoding="utf-8") == "sentinel\n"


def test_mutations_reject_symlinks_to_another_workspace_inside_shared_root(
    store: LocalArtifactStore,
    shared_root: Path,
) -> None:
    source_b_raw = shared_root / "sources" / "source-b" / "raw"
    source_b_raw.mkdir(parents=True)
    marker = source_b_raw / "keep.txt"
    marker.write_text("keep", encoding="utf-8")
    source_a = shared_root / "sources" / "source-a"
    source_a.mkdir(parents=True)
    (source_a / "raw").symlink_to(source_b_raw, target_is_directory=True)

    source_b_manifest = shared_root / "sources" / "source-b" / "manifest.json"
    source_b_manifest.write_text("sentinel\n", encoding="utf-8")
    (source_a / "manifest.json").symlink_to(source_b_manifest)

    with pytest.raises(ValueError, match="must not contain symlinks"):
        store.recreate_directory(source_a / "raw")
    with pytest.raises(ValueError, match="must not contain symlinks"):
        store.write_manifest(source_a / "manifest.json", [{"unsafe": True}])

    assert marker.read_text(encoding="utf-8") == "keep"
    assert source_b_manifest.read_text(encoding="utf-8") == "sentinel\n"


def test_failed_manifest_replace_preserves_previous_file_and_cleans_temporary(
    store: LocalArtifactStore,
    shared_root: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    manifest = shared_root / "manifest.json"
    manifest.write_text("previous\n", encoding="utf-8")

    def fail_replace(*_args: object) -> None:
        raise OSError("replace failed")

    monkeypatch.setattr(os, "replace", fail_replace)
    with pytest.raises(OSError, match="replace failed"):
        store.write_manifest(manifest, [{"version": 2}])

    assert manifest.read_text(encoding="utf-8") == "previous\n"
    assert list(shared_root.iterdir()) == [manifest]


def test_recreate_directory_clears_only_the_requested_directory(
    store: LocalArtifactStore,
    shared_root: Path,
) -> None:
    target = shared_root / "sources" / "source-a" / "raw"
    target.mkdir(parents=True)
    (target / "stale.txt").write_text("stale", encoding="utf-8")
    sibling = target.parent / "keep.txt"
    sibling.write_text("keep", encoding="utf-8")

    recreated = store.recreate_directory(str(target))

    assert recreated == target.resolve()
    assert target.is_dir()
    assert list(target.iterdir()) == []
    assert sibling.read_text(encoding="utf-8") == "keep"
