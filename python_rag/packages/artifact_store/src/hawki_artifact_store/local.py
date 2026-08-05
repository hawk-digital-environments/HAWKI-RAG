"""Root-confined operations on the worker shared volume."""

from __future__ import annotations

import json
import os
import shutil
import tempfile
from collections.abc import Iterable
from contextlib import suppress
from pathlib import Path
from typing import Any
from urllib.parse import unquote, urlsplit


class LocalArtifactStore:
    """Read and write artifacts below one Laravel-supplied shared root."""

    def __init__(self, shared_root: str | Path) -> None:
        root = _absolute_local_path(shared_root)
        if root == Path(root.anchor):
            raise ValueError("shared_root must not be a filesystem root")
        if not root.exists():
            raise FileNotFoundError(f"Shared artifact root was not found: {root}")
        if not root.is_dir():
            raise NotADirectoryError(f"Shared artifact root is not a directory: {root}")
        self._shared_root = root

    @property
    def shared_root(self) -> Path:
        """Return the resolved root that confines every operation."""

        return self._shared_root

    def resolve(self, location: str | Path) -> Path:
        """Resolve a local location and reject paths outside the shared root."""

        path = _absolute_local_path(location)
        if not path.is_relative_to(self._shared_root):
            raise ValueError(
                f"Artifact path must stay below shared root {self._shared_root}: {path}"
            )
        return path

    def relative_path(self, location: str | Path, directory: str | Path) -> str:
        """Return a validated POSIX path relative to an artifact directory."""

        path = self.resolve(location)
        root = self.resolve(directory)
        try:
            return path.relative_to(root).as_posix()
        except ValueError as exc:
            raise ValueError(f"Artifact path is outside its directory: {path}") from exc

    def list_markdown(self, location: str | Path) -> list[str]:
        """Return resolved Markdown files in stable path order."""

        root = self.resolve(location)
        if not root.exists():
            raise FileNotFoundError(f"Markdown directory was not found: {root}")
        if not root.is_dir():
            raise NotADirectoryError(f"Markdown location is not a directory: {root}")

        paths = [
            self.resolve(path)
            for path in root.rglob("*")
            if path.is_file() and path.suffix.lower() in {".md", ".markdown"}
        ]
        return [str(path) for path in sorted(paths)]

    def read_bytes(self, location: str | Path) -> bytes:
        """Read one artifact without transforming its content."""

        return self.resolve(location).read_bytes()

    def read_text(self, location: str | Path) -> str:
        """Read one UTF-8 artifact without transforming its content."""

        return self.resolve(location).read_text(encoding="utf-8")

    def write_manifest(
        self,
        location: str | Path,
        records: Iterable[dict[str, Any]],
    ) -> None:
        """Atomically replace a deterministic JSON manifest."""

        path = self.resolve_for_mutation(location)
        path.parent.mkdir(parents=True, exist_ok=True)
        content = json.dumps(list(records), indent=2, sort_keys=True) + "\n"
        descriptor, temporary_name = tempfile.mkstemp(
            dir=path.parent,
            prefix=f".{path.name}.",
            suffix=".tmp",
        )
        temporary_path = Path(temporary_name)
        try:
            os.fchmod(descriptor, 0o664)
            with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
                handle.write(content)
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary_path, path)
        except BaseException:
            with suppress(OSError):
                os.close(descriptor)
            with suppress(OSError):
                temporary_path.unlink(missing_ok=True)
            raise

    def recreate_directory(self, location: str | Path) -> Path:
        """Recreate one stage directory without allowing shared-root deletion."""

        path = self.resolve_for_mutation(location)
        if path == self._shared_root:
            raise ValueError("Refusing to recreate the shared artifact root")
        if path.exists():
            if not path.is_dir():
                raise NotADirectoryError(
                    f"Artifact directory path is not a directory: {path}"
                )
            shutil.rmtree(path)
        path.mkdir(parents=True, exist_ok=True)
        return path

    def resolve_for_mutation(self, location: str | Path) -> Path:
        """Resolve a mutation target while rejecting every symlink component."""

        path = self.resolve(location)
        lexical_path = _absolute_local_path(location, resolve_symlinks=False)
        if lexical_path != path:
            raise ValueError(
                f"Artifact mutation path must not contain symlinks: {location}"
            )
        return path


def _absolute_local_path(
    location: str | Path,
    *,
    resolve_symlinks: bool = True,
) -> Path:
    raw_location = os.fspath(location)
    parsed = urlsplit(raw_location)
    if parsed.scheme:
        if parsed.scheme.lower() != "file" or parsed.netloc:
            raise ValueError(
                f"Only local paths and file:/// URIs are supported: {location}"
            )
        if parsed.query or parsed.fragment:
            raise ValueError(
                f"File URI must not contain query or fragment data: {location}"
            )
        raw_location = unquote(parsed.path)

    path = Path(raw_location).expanduser()
    if not path.is_absolute():
        raise ValueError(f"Artifact path must be absolute: {location}")
    path = Path(os.path.abspath(path))
    return path.resolve() if resolve_symlinks else path
