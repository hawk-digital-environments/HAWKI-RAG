"""RAG-Anything passthrough artifacts for unsupported direct-extract files."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path

PASSTHROUGH_METADATA_FILENAME = "rawki_passthrough.json"


def write_raganything_passthrough(
    raw_file: Path,
    output_dir: Path,
    error: Exception,
) -> int:
    """Write Markdown and metadata so the indexer can parse the original file."""

    output_dir.mkdir(parents=True, exist_ok=True)
    raw_path = str(raw_file.resolve())
    file_hash = _sha256_file(raw_file)
    markdown_path = output_dir / "content_markdown.md"
    markdown_path.write_text(
        "\n".join(
            [
                f"# {raw_file.name}",
                "",
                "The direct converter could not extract Markdown for this file.",
                "The original file is attached for RAG-Anything/MinerU native parsing or OCR during graph ingestion.",
                "",
                f"Original file: `{raw_file.name}`",
                f"Original SHA-256: `{file_hash}`",
                "",
            ]
        ),
        encoding="utf-8",
    )

    metadata: dict[str, object] = {
        "source_format": "raganything_passthrough",
        "original_filename": raw_file.name,
        "original_path": raw_path,
        "source_file": raw_path,
        "file_path": raw_path,
        "converted_path": str(markdown_path.resolve()),
        "converter_fallback": "raganything_passthrough",
        "converter_error": str(error),
        "original_sha256": file_hash,
    }
    if _is_image_file(raw_file):
        metadata["image_path"] = raw_path
        metadata["images"] = [raw_path]

    (output_dir / PASSTHROUGH_METADATA_FILENAME).write_text(
        json.dumps(metadata, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    return 1


def _is_image_file(path: Path) -> bool:
    return path.suffix.lower() in {
        ".bmp",
        ".gif",
        ".jpeg",
        ".jpg",
        ".png",
        ".tif",
        ".tiff",
        ".webp",
    }


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


__all__ = ["PASSTHROUGH_METADATA_FILENAME", "write_raganything_passthrough"]
