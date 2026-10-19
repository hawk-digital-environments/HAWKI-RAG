"""Persist converter directory choices so existing artifact IDs never move."""

import hashlib
import json
import logging
from pathlib import Path

from hawki_converter_worker.conversion.discovery import converter_output_directory_name

PATH_MAP_FILENAME = ".rawki-converter-paths.json"
logger = logging.getLogger(__name__)


def plan_output_paths(
    candidates: list[Path], raw_root: Path, markdown_root: Path
) -> list[tuple[Path, Path]]:
    """Preflight every output before deleting any previous converted content.

    Adopt legacy directories only when their original absolute-path hash proves
    the mapping. The persisted relative-input map then survives storage moves.
    Unmapped old outputs require explicit recovery; never guess their owner.
    """

    map_path = markdown_root / PATH_MAP_FILENAME
    mapping: dict[str, str] = {}
    if map_path.exists():
        data = json.loads(map_path.read_text(encoding="utf-8"))
        if not isinstance(data, dict) or data.get("version") != 1:
            raise RuntimeError("Unsupported converter path map")
        mapping = data.get("paths")
        if not isinstance(mapping, dict):
            raise RuntimeError("Invalid converter path map")
    for relative, directory in mapping.items():
        if (
            not isinstance(relative, str)
            or not relative
            or Path(relative).is_absolute()
            or ".." in Path(relative).parts
            or not isinstance(directory, str)
            or Path(directory).name != directory
            or directory in {"", ".", "..", PATH_MAP_FILENAME}
        ):
            raise RuntimeError("Unsafe converter path map")

    outputs: list[tuple[Path, Path]] = []
    for raw_file in candidates:
        relative = raw_file.relative_to(raw_root).as_posix()
        if relative not in mapping:
            stable_name = converter_output_directory_name(raw_file, raw_root)
            # Keep the exact old name when upgrading an existing output tree.
            stem = (
                "".join(
                    character.lower() if character.isalnum() else "-"
                    for character in raw_file.stem
                ).strip("-")
                or "document"
            )
            digest = hashlib.sha256(
                str(raw_file.resolve()).encode("utf-8")
            ).hexdigest()[:8]
            legacy_name = f"{stem}-{digest}"
            if (markdown_root / legacy_name).exists():
                mapping[relative] = legacy_name
                logger.info(
                    "converter:preserve_legacy_output relative_path=%s directory=%s",
                    relative,
                    legacy_name,
                )
            else:
                mapping[relative] = stable_name
        outputs.append((raw_file, markdown_root / mapping[relative]))

    if len(set(mapping.values())) != len(mapping):
        raise RuntimeError("Converter output paths collide")
    known_names = set(mapping.values()) | {PATH_MAP_FILENAME}
    for existing in markdown_root.iterdir():
        if existing.name not in known_names or existing.is_symlink():
            raise RuntimeError(
                "Unmapped converter output; preserve the artifact tree and restore "
                f"its {PATH_MAP_FILENAME} before reconversion: {existing.name}"
            )
    # Save before conversion so a failed extraction can retry the same names.
    temporary = map_path.with_suffix(".tmp")
    temporary.write_text(
        json.dumps({"version": 1, "paths": mapping}, sort_keys=True, indent=2),
        encoding="utf-8",
    )
    temporary.replace(map_path)
    return outputs
