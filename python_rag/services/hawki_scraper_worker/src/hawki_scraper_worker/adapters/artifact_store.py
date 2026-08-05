"""Stage uploaded source files in the mounted local artifact store."""

from __future__ import annotations

from pathlib import Path
import shutil
from typing import Any

from hawki_artifact_store.local import LocalArtifactStore


class LocalUploadArtifactStager:
    """Copy an uploaded source into a fresh raw-artifact directory."""

    def stage(
        self,
        workflow_input: dict[str, Any],
        source_id: str,
        raw_dir: str,
        artifact_store: LocalArtifactStore,
    ) -> dict[str, Any] | None:
        upload = workflow_input.get("upload")
        if not isinstance(upload, dict):
            return None

        uploaded_path = upload.get("local_path") or upload.get("uploaded_path")
        if not isinstance(uploaded_path, str) or not uploaded_path.strip():
            return None

        source = artifact_store.resolve(uploaded_path)
        if not source.exists() or not source.is_file():
            raise RuntimeError(f"Uploaded source file was not found: {source}")

        target_name = str(upload.get("target_name") or source.name)
        if Path(target_name).name != target_name or target_name in {"", ".", ".."}:
            raise RuntimeError("Uploaded target_name must be a plain file name.")

        raw_root = artifact_store.resolve_for_mutation(raw_dir)
        markdown_dir = workflow_input.get("markdown_output_path")
        markdown_root = None
        if isinstance(markdown_dir, str) and markdown_dir.strip():
            markdown_root = artifact_store.resolve_for_mutation(markdown_dir)

        if markdown_root is not None and (
            raw_root == markdown_root
            or raw_root.is_relative_to(markdown_root)
            or markdown_root.is_relative_to(raw_root)
        ):
            raise RuntimeError(
                "Raw and Markdown artifact directories must not overlap."
            )

        target_roots = [raw_root]
        if markdown_root is not None:
            target_roots.append(markdown_root)
        if any(source == root or source.is_relative_to(root) for root in target_roots):
            raise RuntimeError(
                "Uploaded source file must be outside the artifact directories being reset."
            )

        target = artifact_store.resolve(raw_root / target_name)
        artifact_store.relative_path(target, raw_root)

        raw_root = artifact_store.recreate_directory(raw_root)
        if markdown_root is not None:
            artifact_store.recreate_directory(markdown_root)
        if source != target:
            shutil.copy2(source, target)

        return {
            "source_id": source_id,
            "external_job_id": None,
            "raw_dir": str(raw_root),
            "files_found": 1,
            "status": "success",
            "error_details": None,
            "uploaded_file": str(target),
        }
