"""Immutable upload publication in the shared worker filesystem."""

import hashlib
import json
import os
import re
import unicodedata
from pathlib import Path
from typing import BinaryIO

from .input import MAX_FILE_BYTES, ManagedError


def hash_upload(stream: BinaryIO) -> str:
    checksum = hashlib.sha256()
    size = 0
    stream.seek(0)
    while chunk := stream.read(1024 * 1024):
        size += len(chunk)
        if size > MAX_FILE_BYTES:
            raise ManagedError(
                422, "The file field must not be greater than 102400 kilobytes."
            )
        checksum.update(chunk)
    stream.seek(0)
    return checksum.hexdigest()


class UploadStorage:
    def __init__(self, root: str) -> None:
        path = Path(root)
        if not path.is_absolute() or ".." in path.parts or path == Path("/"):
            raise ValueError("Shared root must be a canonical absolute directory")
        self.root = path

    def _publish(
        self, path: Path, stream: BinaryIO, checksum: str, *, mode: int = 0o644
    ) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        temporary = path.with_name("." + path.name + ".publishing")
        if path.exists():
            with path.open("rb") as existing:
                if hash_upload(existing) != checksum:
                    raise ManagedError(
                        502,
                        "Durable upload changed; restore its original bytes before retrying.",
                    )
            temporary.unlink(missing_ok=True)
            directory = os.open(path.parent, os.O_RDONLY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
            return
        # The committed intent owns this staging path too. Publication holds
        # the document's session lock, so a retry can discard a crashed copy.
        temporary.unlink(missing_ok=True)
        try:
            stream.seek(0)
            descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
            with os.fdopen(descriptor, "wb") as output:
                while chunk := stream.read(1024 * 1024):
                    output.write(chunk)
                output.flush()
                os.fsync(output.fileno())
            # Never overwrite a path that a concurrent retry already published.
            try:
                os.link(temporary, path)
            except FileExistsError:
                with path.open("rb") as existing:
                    if hash_upload(existing) != checksum:
                        raise ManagedError(
                            502, "Durable upload content conflicts."
                        ) from None
            directory = os.open(path.parent, os.O_RDONLY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
        finally:
            temporary.unlink(missing_ok=True)

    def store(
        self, task_id: str, filename: str, suffix: str, stream: BinaryIO, checksum: str
    ) -> str:
        target = Path(self.destination(task_id, filename, suffix))
        self._publish(target, stream, checksum)
        return str(target)

    def destination(self, task_id: str, filename: str, suffix: str) -> str:
        """Allocate a canonical path without publishing or making directories."""
        basename = Path(filename).stem
        ascii_name = (
            unicodedata.normalize("NFKD", basename).encode("ascii", "ignore").decode()
        )
        slug = (
            re.sub(r"[^a-z0-9]+", "-", ascii_name.lower()).strip("-")
            or "uploaded-document"
        )
        # Persisted task identity supplies the random suffix; retries retain it.
        target = self.root / task_id / (slug[:150] + "-" + task_id[-8:] + "." + suffix)
        return str(target)

    def profile_path(self, source_id: str) -> str:
        return str(
            self.root / "sources" / source_id / "secrets" / "custom_converter.json"
        )

    def profile(self, source_id: str, values: dict) -> str:
        from io import BytesIO

        path = Path(self.profile_path(source_id))
        raw = json.dumps(values, sort_keys=True).encode()
        self._publish(path, BytesIO(raw), hashlib.sha256(raw).hexdigest(), mode=0o600)
        os.chmod(path, 0o600)
        return str(path)
