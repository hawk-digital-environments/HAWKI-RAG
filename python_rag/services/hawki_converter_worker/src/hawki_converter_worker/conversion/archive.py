"""Safe extraction of converter ZIP responses."""

from __future__ import annotations

import io
from pathlib import Path
import shutil
import zipfile


def unpack_converter_archive(content: bytes, output_dir: Path) -> int:
    """Extract a converter ZIP and return the number of Markdown files."""

    archive_data = io.BytesIO(content)
    if not zipfile.is_zipfile(archive_data):
        raise RuntimeError("Converter returned a non-ZIP response.")

    archive_data.seek(0)
    markdown_files_created = 0
    output_root = output_dir.resolve()
    with zipfile.ZipFile(archive_data) as archive:
        for member in archive.infolist():
            if member.is_dir():
                continue

            member_path = Path(member.filename)
            if member_path.is_absolute() or ".." in member_path.parts:
                raise RuntimeError(
                    f"Converter ZIP contained an unsafe path: {member.filename}"
                )

            target = (output_root / member_path).resolve()
            try:
                target.relative_to(output_root)
            except ValueError as exc:
                raise RuntimeError(
                    f"Converter ZIP path escaped output directory: {member.filename}"
                ) from exc

            target.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(member) as source, target.open("wb") as destination:
                shutil.copyfileobj(source, destination)

            if target.suffix.lower() in {".md", ".markdown"}:
                markdown_files_created += 1

    return markdown_files_created


__all__ = ["unpack_converter_archive"]
