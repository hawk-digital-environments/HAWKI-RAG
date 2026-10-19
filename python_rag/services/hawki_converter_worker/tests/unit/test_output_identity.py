"""Converter output identities survive retries and storage relocation."""

import hashlib
import io
import json
from pathlib import Path
import shutil
import zipfile

import pytest

from hawki_artifact_store.local import LocalArtifactStore
from hawki_converter_worker.conversion.direct import convert_files_direct
from hawki_converter_worker.conversion.output_paths import PATH_MAP_FILENAME
from hawki_rag_contracts.pipeline.identity import document_id


class Extractor:
    def __init__(self):
        self.calls = 0

    def extract(self, raw_file: Path) -> bytes:
        self.calls += 1
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, "w") as archive:
            archive.writestr("output/chunks/00001.md", raw_file.read_text())
        return buffer.getvalue()


def convert(root: Path, extractor=None):
    return convert_files_direct(
        "source-a",
        str(root / "raw"),
        str(root / "markdown"),
        artifact_store=LocalArtifactStore(root),
        extract_client=extractor or Extractor(),
    )


def write_inputs(root: Path):
    for relative in ("a/page.txt", "b/page.txt"):
        path = root / "raw" / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("HAWKI uses Qdrant.", encoding="utf-8")


def output_ids(root: Path):
    return {
        path.relative_to(root / "markdown").as_posix(): document_id(
            "source-a", path.relative_to(root / "markdown")
        )
        for path in (root / "markdown").rglob("*.md")
    }


def test_new_output_names_do_not_depend_on_storage_root(tmp_path):
    first, second = tmp_path / "first", tmp_path / "second"
    write_inputs(first)
    write_inputs(second)
    convert(first)
    convert(second)
    assert output_ids(first) == output_ids(second)
    assert len(output_ids(first)) == 2
    before = output_ids(first)
    convert(first)
    assert output_ids(first) == before


def test_legacy_outputs_are_preserved_then_survive_relocation(tmp_path):
    first = tmp_path / "first"
    write_inputs(first)
    for raw in (first / "raw").rglob("*.txt"):
        digest = hashlib.sha256(str(raw.resolve()).encode()).hexdigest()[:8]
        path = first / "markdown" / f"page-{digest}" / "output/chunks/00001.md"
        path.parent.mkdir(parents=True)
        path.write_text("old content", encoding="utf-8")
    before = output_ids(first)
    convert(first)
    assert output_ids(first) == before
    assert (first / "markdown" / PATH_MAP_FILENAME).exists()
    second = tmp_path / "moved"
    shutil.copytree(first, second)
    convert(second)
    assert output_ids(second) == before


def test_unmapped_legacy_outputs_fail_before_changing_content(tmp_path):
    write_inputs(tmp_path)
    legacy = tmp_path / "markdown" / "page-old-location" / "page.md"
    legacy.parent.mkdir(parents=True)
    legacy.write_text("preserve me", encoding="utf-8")
    extractor = Extractor()
    with pytest.raises(RuntimeError, match="Unmapped converter output"):
        convert(tmp_path, extractor)
    assert extractor.calls == 0
    assert legacy.read_text() == "preserve me"
    assert not (tmp_path / "markdown" / PATH_MAP_FILENAME).exists()


@pytest.mark.parametrize(
    "mapping",
    [
        {"a/page.txt": "../escape"},
        {"a/page.txt": "same", "b/page.txt": "same"},
    ],
)
def test_invalid_or_colliding_path_map_aborts_before_conversion(tmp_path, mapping):
    write_inputs(tmp_path)
    root = tmp_path / "markdown"
    root.mkdir()
    path = root / PATH_MAP_FILENAME
    path.write_text(json.dumps({"version": 1, "paths": mapping}), encoding="utf-8")
    extractor = Extractor()
    with pytest.raises(RuntimeError, match="Unsafe|collide"):
        convert(tmp_path, extractor)
    assert extractor.calls == 0
