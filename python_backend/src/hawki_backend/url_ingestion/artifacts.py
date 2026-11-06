"""Verify the existing ingest manifest; never invent a URL-derived content hash."""

import hashlib
import json
from pathlib import Path, PurePosixPath

from hawki_rag_contracts.pipeline.identity import document_id

from .errors import UrlIngestionError


def verify_manifest(generation: dict, event: object) -> dict:
    wire = generation["workflow_input"]
    expected = Path(wire["ingest_manifest_path"])
    root = Path(wire["markdown_output_path"]).resolve()
    base = (
        Path(wire["storage"]["shared_root"]).resolve()
        / "sources"
        / generation["source_id"]
    )
    if expected.resolve() != base / "ingest/manifest.json" or root != base / "markdown":
        raise UrlIngestionError(
            409, "URL artifact paths differ from the frozen source."
        )
    if event.manifest is None or event.manifest.uri.removeprefix("file://") != str(
        expected
    ):
        raise UrlIngestionError(409, "URL ingest manifest identity differs.")
    try:
        with expected.open("rb") as stream:
            raw = stream.read(8 * 1024 * 1024 + 1)
        checksum = hashlib.sha256(raw).hexdigest()
        if len(raw) > 8 * 1024 * 1024 or (
            event.manifest.sha256 and event.manifest.sha256 != checksum
        ):
            raise ValueError()
        records = json.loads(raw)
        if not isinstance(records, list) or len(records) > 1000:
            raise ValueError()
        ids = set()
        for record in records:
            relative = PurePosixPath(record["relative_path"])
            if (
                relative.is_absolute()
                or ".." in relative.parts
                or not relative.parts
                or str(relative) != record["relative_path"]
                or record["document_id"]
                != document_id(generation["source_id"], str(relative))
                or record["document_id"] in ids
                or Path(record["markdown_path"]) != root / str(relative)
                or not Path(record["markdown_path"]).resolve().is_relative_to(root)
            ):
                raise ValueError()
            ids.add(record["document_id"])
        if not set(generation["writer_state"].get("managed_targets", {})).issubset(ids):
            raise ValueError()
    except (OSError, ValueError, KeyError, TypeError):
        raise UrlIngestionError(
            409, "URL ingest manifest could not be verified."
        ) from None
    return {
        "version": 1,
        "status": "unknown",
        "evidence": {"kind": "ingest-manifest-sha256", "sha256": checksum},
    }
