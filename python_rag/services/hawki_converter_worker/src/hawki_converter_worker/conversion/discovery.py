"""Safe local file discovery for converter inputs and outputs."""

from __future__ import annotations

import hashlib
from pathlib import Path

from hawki_converter_worker.domain.ports import ConverterArtifactStorePort

SCRAPER_BOOKKEEPING_FILENAMES = frozenset(
    {
        "crawler.log",
        "job_state.json",
        "summary.json",
        "urls_index.json",
    }
)


def resolve_conversion_directory(
    artifact_store: ConverterArtifactStorePort,
    location: str,
    label: str,
    *,
    must_exist: bool = True,
) -> Path:
    """Resolve a workflow directory inside the configured artifact root."""

    root = artifact_store.resolve(location)
    if must_exist and (not root.exists() or not root.is_dir()):
        raise RuntimeError(f"{label.capitalize()} directory was not found: {root}")
    return root


def find_raw_conversion_candidates(
    artifact_store: ConverterArtifactStorePort,
    raw_root: Path,
) -> list[Path]:
    """Return safe input files, excluding scraper bookkeeping artifacts."""

    skip_bookkeeping = _looks_like_scraper_output_directory(raw_root)
    candidates: list[Path] = []
    for path in sorted(raw_root.rglob("*")):
        if not path.is_file() or (
            skip_bookkeeping and path.name in SCRAPER_BOOKKEEPING_FILENAMES
        ):
            continue

        resolved = artifact_store.resolve(path)
        artifact_store.relative_path(resolved, raw_root)
        candidates.append(resolved)
    return candidates


def converter_output_directory_name(raw_file: Path) -> str:
    """Return a stable collision-resistant directory name for one input file."""

    safe_stem = "".join(
        character.lower() if character.isalnum() else "-" for character in raw_file.stem
    ).strip("-")
    digest = hashlib.sha256(str(raw_file.resolve()).encode("utf-8")).hexdigest()[:8]
    return f"{safe_stem or 'document'}-{digest}"


def _looks_like_scraper_output_directory(raw_root: Path) -> bool:
    return (raw_root / "job_state.json").is_file() or (
        raw_root / "urls_index.json"
    ).is_file()


__all__ = [
    "SCRAPER_BOOKKEEPING_FILENAMES",
    "converter_output_directory_name",
    "find_raw_conversion_candidates",
    "resolve_conversion_directory",
]
