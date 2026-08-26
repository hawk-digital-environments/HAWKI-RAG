"""Direct file conversion and unsupported-file passthrough workflow."""

from __future__ import annotations

from dataclasses import dataclass
import logging
from pathlib import Path
import shutil

from hawki_rag_contracts.ingestion import IngestionStatus

from hawki_converter_worker.conversion.archive import unpack_converter_archive
from hawki_converter_worker.conversion.discovery import (
    converter_output_directory_name,
    find_raw_conversion_candidates,
    resolve_conversion_directory,
)
from hawki_converter_worker.conversion.passthrough import (
    write_raganything_passthrough,
)
from hawki_converter_worker.domain.errors import DirectExtractUnsupportedFileError
from hawki_converter_worker.domain.models import ConversionRunResult
from hawki_converter_worker.domain.ports import (
    ConverterArtifactStorePort,
    DirectExtractClientPort,
)

logger = logging.getLogger(__name__)


@dataclass(frozen=True, slots=True)
class _ConvertedFile:
    source_path: str
    markdown_files_created: int
    used_passthrough: bool


def convert_files_direct(
    source_id: str,
    raw_dir: str,
    markdown_dir: str,
    *,
    artifact_store: ConverterArtifactStorePort,
    extract_client: DirectExtractClientPort,
) -> ConversionRunResult:
    """Convert all safe raw artifacts through the synchronous extract endpoint."""

    raw_root = resolve_conversion_directory(artifact_store, raw_dir, "raw")
    markdown_root = resolve_conversion_directory(
        artifact_store,
        markdown_dir,
        "markdown",
        must_exist=False,
    )
    markdown_root.mkdir(parents=True, exist_ok=True)
    candidates = find_raw_conversion_candidates(artifact_store, raw_root)

    if not candidates:
        return ConversionRunResult(
            source_id=source_id,
            markdown_dir=str(markdown_root),
            status=IngestionStatus.FAILED,
            error_details="No files were found for the converter.",
        )

    converted_files: list[str] = []
    passthrough_files: list[str] = []
    markdown_files_created = 0
    for raw_file in candidates:
        result = _convert_candidate(raw_file, markdown_root, extract_client)
        markdown_files_created += result.markdown_files_created
        converted_files.append(result.source_path)
        if result.used_passthrough:
            passthrough_files.append(result.source_path)

    return ConversionRunResult(
        source_id=source_id,
        markdown_dir=str(markdown_root),
        markdown_files_created=markdown_files_created,
        converted_files=tuple(converted_files),
        passthrough_files=tuple(passthrough_files),
        status=(
            IngestionStatus.SUCCESS
            if markdown_files_created > 0
            else IngestionStatus.FAILED
        ),
        error_details=None
        if markdown_files_created > 0
        else "Converter did not produce Markdown files.",
    )


def _convert_candidate(
    raw_file: Path,
    markdown_root: Path,
    extract_client: DirectExtractClientPort,
) -> _ConvertedFile:
    source_path = str(raw_file)
    output_dir = markdown_root / converter_output_directory_name(raw_file)
    if output_dir.exists():
        shutil.rmtree(output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    try:
        archive = extract_client.extract(raw_file)
        created = unpack_converter_archive(archive, output_dir)
        return _ConvertedFile(source_path, created, False)
    except DirectExtractUnsupportedFileError as exc:
        created = write_raganything_passthrough(raw_file, output_dir, exc)
        logger.info(
            "converter:direct_extract_passthrough file=%s reason=%s",
            raw_file,
            exc,
        )
        return _ConvertedFile(source_path, created, True)


__all__ = ["convert_files_direct"]
