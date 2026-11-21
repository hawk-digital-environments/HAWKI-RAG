"""Keep the historical compatibility fixture tied to real Python upload publication."""

import hashlib
import json
from io import BytesIO
from pathlib import Path

import pytest

from hawki_backend.managed_documents.artifacts import UploadStorage

FIXTURE_PATH = Path(__file__).resolve().parents[1] / "fixtures/managed_upload.fixture"
FIXTURE = json.loads(FIXTURE_PATH.read_text(encoding="utf-8"))


@pytest.mark.parametrize(
    "fixture", [FIXTURE, *FIXTURE["cases"]], ids=lambda case: case["filename"]
)
def test_compatibility_download_fixture_uses_the_real_upload_destination(
    tmp_path: Path,
    fixture: dict,
) -> None:
    storage = UploadStorage(str(tmp_path))
    contents = fixture["contents"].encode()
    destination = storage.store(
        fixture["task_id"],
        fixture["filename"],
        fixture["suffix"],
        BytesIO(contents),
        hashlib.sha256(contents).hexdigest(),
    )
    assert (
        Path(destination).relative_to(tmp_path).as_posix() == fixture["relative_path"]
    )
    assert Path(destination).read_bytes() == contents
