"""Independent PHP parity oracle and immutable publication failure tests."""

import json
import os
import subprocess
from pathlib import Path

import pytest

from hawki_backend.text_ingestion.artifacts import (
    TextArtifactError,
    TextArtifactStorage,
)
from hawki_backend.text_ingestion.validation import TextIngestionRequest
from hawki_backend.text_ingestion.identity import metadata_bytes, legacy_json
from .conftest import payload


def store(root, value=None):
    body = TextIngestionRequest.model_validate(value or payload())
    artifact = TextArtifactStorage(str(root)).store(
        fingerprint=body.fingerprint(), **body.model_dump()
    )
    return body, artifact


@pytest.mark.parametrize(
    "metadata",
    [
        {},
        {"nested": {"z": 1, "a": []}, "url": "https://example.test/ä"},
        {"n": 1.0, "small": 1e-7, "large": 1e20},
        {"empty": {}, "numeric": {"0": "a", "1": "b"}},
        *[
            {"key" + char: char}
            for char in (
                *map(chr, range(32)),
                " ",
                "/",
                '"',
                "\\",
                "~",
                "\x7f",
                "\x80",
                "é",
                "\u2028",
                "\u2029",
                "🌍",
                "e\u0301",
            )
        ],
    ],
)
def test_real_php_fingerprint_and_artifact_bytes(tmp_path, metadata):
    value = {**payload(), "metadata": metadata}
    oracle = json.loads(
        subprocess.run(
            [
                "php",
                str(
                    Path(__file__).resolve().parents[3]
                    / "tests/Support/text_ingestion_parity.php"
                ),
            ],
            input=json.dumps(value),
            text=True,
            capture_output=True,
            check=True,
        ).stdout
    )
    body, artifact = store(tmp_path, value)
    assert body.fingerprint() == oracle["fingerprint"]
    assert metadata_bytes(metadata) == oracle["metadata_bytes"]
    assert legacy_json(metadata) == oracle["metadata_json"]
    assert (
        legacy_json(body.fingerprint_values(), sort_keys=True, escape_slashes=True)
        == oracle["canonical_json"]
    )
    path = Path(artifact.markdown_path)
    assert (
        path.relative_to(tmp_path).as_posix()
        == f"sources/{artifact.source_id}/revisions/{body.fingerprint()}/markdown/document.md"
    )
    assert path.read_bytes() == value["text"].encode()
    assert path.with_name("rawki_passthrough.json").read_text() == oracle["passthrough"]
    assert not list(tmp_path.rglob("chunks.md"))
    assert path.stat().st_mode & 0o777 == 0o664
    assert path.parent.stat().st_mode & 0o2777 == 0o2775


def test_matching_artifact_reused_and_conflict_refused(tmp_path):
    body, artifact = store(tmp_path)
    store(tmp_path)
    path = Path(artifact.markdown_path)
    path.write_text("conflicting existing content")
    with pytest.raises(TextArtifactError):
        store(tmp_path)
    assert path.read_text() == "conflicting existing content"


def test_atomic_publication_failure_has_no_partial_document(tmp_path, monkeypatch):
    def fail(*args):
        raise OSError("no hardlink support")

    monkeypatch.setattr(os, "link", fail)
    with pytest.raises(TextArtifactError):
        store(tmp_path)
    assert not list(tmp_path.rglob("document.md"))
    assert not list(tmp_path.rglob("*.tmp.*"))


def test_reconciliation_marker_roundtrip(tmp_path):
    _, artifact = store(tmp_path)
    storage = TextArtifactStorage(str(tmp_path))
    storage.mark_for_reconciliation(artifact)
    storage.mark_for_reconciliation(artifact)
    assert len(list(tmp_path.rglob("reconciliation-required.json"))) == 1
    storage.clear_reconciliation_marker(artifact)
    assert not list(tmp_path.rglob("reconciliation-required.json"))


def test_failure_between_document_and_metadata_retains_reconciliation_marker(
    tmp_path, monkeypatch
):
    storage = TextArtifactStorage(str(tmp_path))
    original = storage._write_immutable

    def fail_metadata(path, contents, **kwargs):
        if path.endswith("rawki_passthrough.json"):
            raise OSError("simulated storage interruption")
        original(path, contents, **kwargs)

    monkeypatch.setattr(storage, "_write_immutable", fail_metadata)
    body = TextIngestionRequest.model_validate(payload())
    with pytest.raises(TextArtifactError):
        storage.store(fingerprint=body.fingerprint(), **body.model_dump())
    assert len(list(tmp_path.rglob("reconciliation-required.json"))) == 1
    assert len(list(tmp_path.rglob("document.md"))) == 1


@pytest.mark.parametrize("character", ["\x7f", "é", "🌍", "\x1f"])
@pytest.mark.parametrize("size,accepted", [(65_536, True), (65_537, False)])
def test_php_metadata_limit_at_exact_encoded_boundary(character, size, accepted):
    from pydantic import ValidationError

    cost = len(legacy_json(character)) - 2
    count, remainder = divmod(size - 8, cost)
    value = {**payload(), "metadata": {"a": character * count + "x" * remainder}}
    oracle = json.loads(
        subprocess.run(
            [
                "php",
                str(
                    Path(__file__).resolve().parents[3]
                    / "tests/Support/text_ingestion_parity.php"
                ),
            ],
            input=json.dumps(value),
            text=True,
            capture_output=True,
            check=True,
        ).stdout
    )
    assert oracle["metadata_bytes"] == metadata_bytes(value["metadata"]) == size
    if accepted:
        assert (
            TextIngestionRequest.model_validate(value).fingerprint()
            == oracle["fingerprint"]
        )
    else:
        with pytest.raises(ValidationError):
            TextIngestionRequest.model_validate(value)


def test_twenty_thousand_del_characters_match_php_acceptance():
    value = {**payload(), "metadata": {"a": "\x7f" * 20_000}}
    assert metadata_bytes(value["metadata"]) == 20_008
    assert TextIngestionRequest.model_validate(value).metadata == value["metadata"]


def test_literal_unicode_escape_is_not_replaced():
    assert legacy_json({"a": r"\u007f"}) == r'{"a":"\\u007f"}'
