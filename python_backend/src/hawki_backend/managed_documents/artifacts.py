"""Immutable upload publication in the shared worker filesystem."""

import hashlib
import json
import os
import re
import shutil
import unicodedata
from pathlib import Path
from typing import BinaryIO

from .input import MAX_FILE_BYTES, ManagedError
from hawki_backend.historical_archive import require_unreserved


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

    def delete_revision(self, revision: dict) -> None:
        """Remove only a drained Python revision's frozen source and upload.

        The caller holds the document cleanup lock and has verified writer
        quiescence and sink deletion. Validate every destination before mutation;
        retries tolerate paths already removed by an earlier partial attempt.
        """
        require_unreserved(revision)
        source_id, task_id = revision["source_id"], revision["task_id"]
        if not re.fullmatch(r"source_[0-9a-f]{32}", source_id) or not re.fullmatch(
            r"task_managed_[0-9a-f]{32}", task_id
        ):
            raise ValueError("Invalid Python managed artifact identity")
        wire = revision["workflow_input"]
        source = self.root / "sources" / source_id
        upload = Path(revision["upload_path"])
        expected = {
            "raw_output_path": source / "raw",
            "markdown_output_path": source / "markdown",
            "ingest_manifest_path": source / "ingest/manifest.json",
        }
        if (
            Path(wire["storage"]["shared_root"]) != self.root
            or wire["source_id"] != source_id
            or wire["task_id"] != task_id
            or wire["managed_document_id"] != revision["managed_document_id"]
            or upload.parent != self.root / task_id
            or wire["upload"]["local_path"] != str(upload)
            or any(Path(wire[key]) != path for key, path in expected.items())
        ):
            raise ValueError("Managed artifact paths differ from frozen ownership")
        for path in (source, upload):
            if not path.is_absolute() or path.resolve() != path or path.is_symlink():
                raise ValueError("Managed artifact symlink or noncanonical path")
        if source.exists() and any(path.is_symlink() for path in source.rglob("*")):
            raise ValueError("Managed source contains an unverified artifact link")
        if upload.exists():
            with upload.open("rb") as stream:
                if hash_upload(stream) != revision["source_checksum"]:
                    raise ValueError("Managed upload checksum changed")
        if source.exists():
            shutil.rmtree(source)
        upload.unlink(missing_ok=True)
        # Other files in a task directory are never inferred to be ours.
        if upload.parent.is_dir() and not any(upload.parent.iterdir()):
            upload.parent.rmdir()

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
