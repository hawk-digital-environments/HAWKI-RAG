"""Resume-state planning for crawl-ingest runner."""

from __future__ import annotations

import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from application.cli.commands.estimation import utc_now_iso
from application.cli.commands.resume import load_resume_state, safe_state_filename, save_resume_state_payload


@dataclass(slots=True)
class ResumePlan:
    """Resolved resume state for a runner invocation."""

    doc_ids: set[str]
    state_path: Path | None
    metadata: dict[str, Any]
    resume_mode: bool
    key_parts: list[str]


def prepare_resume_plan(
    args: Any,
    root: Path,
    *,
    automation_mode: bool,
    configured_resume_mode: str,
) -> ResumePlan:
    """Resolve resume state, user choice, and metadata for a crawl-ingest run."""

    resume_doc_ids: set[str] = set()
    resume_state_path: Path | None = None
    resume_metadata: dict[str, Any] = {}
    resume_mode = False

    key_parts = build_resume_key_parts(args, root)
    if args.dry or args.estimate_only:
        return ResumePlan(
            doc_ids=resume_doc_ids,
            state_path=resume_state_path,
            metadata=resume_metadata,
            resume_mode=resume_mode,
            key_parts=key_parts,
        )

    state_dir = Path(args.resume_state_dir).expanduser().resolve()
    resume_key = "::".join(key_parts)
    resume_state_path = state_dir / safe_state_filename(resume_key)
    existing_ids = load_resume_state(resume_state_path)
    resume_metadata = build_resume_metadata(args, root)

    if existing_ids:
        print(f"Found previous ingest state for '{key_parts[0]}' with {len(existing_ids)} documents.")
        choice = choose_resume_choice(
            args,
            automation_mode=automation_mode,
            configured_resume_mode=configured_resume_mode,
        )
        if choice in {"", "resume"}:
            resume_mode = True
            resume_doc_ids = existing_ids
            print(f"Resuming ingest; skipping {len(resume_doc_ids)} documents already processed.")
        else:
            try:
                resume_state_path.unlink(missing_ok=True)
            except Exception as exc:
                print(f"Warning: failed to remove existing resume state: {exc}", file=sys.stderr)
            print("Starting fresh; previous state will be replaced.")
    else:
        print(f"No previous ingest state found for '{key_parts[0]}'. Starting fresh.")

    return ResumePlan(
        doc_ids=resume_doc_ids,
        state_path=resume_state_path,
        metadata=resume_metadata,
        resume_mode=resume_mode,
        key_parts=key_parts,
    )


def build_resume_key_parts(args: Any, root: Path) -> list[str]:
    """Build the stable resume key parts used for resume-state filenames."""

    key_parts = [args.collection or "default", str(root), args.base_url.rstrip("/")]
    if args.graph_only:
        key_parts.append("graph_only")
    if args.neo4j_database:
        key_parts.append(f"neo4j_db={args.neo4j_database}")
    return key_parts


def build_resume_metadata(args: Any, root: Path) -> dict[str, Any]:
    """Build metadata stored alongside completed resume document ids."""

    return {
        "collection": args.collection,
        "root": str(root),
        "base_url": args.base_url,
        "graph_only": bool(args.graph_only),
        "graph": bool(args.graph),
        "neo4j_database": args.neo4j_database or None,
    }


def choose_resume_choice(
    args: Any,
    *,
    automation_mode: bool,
    configured_resume_mode: str,
) -> str:
    """Choose whether to resume or start fresh using args, env mode, and stdin."""

    if args.resume:
        return "resume"
    if args.start:
        return "start"
    if automation_mode or not sys.stdin.isatty():
        choice = configured_resume_mode
        if choice == "ask":
            choice = "resume"
        print(f"Automation/non-interactive mode selected ingest resume mode: {choice}.")
        return choice
    if configured_resume_mode != "ask":
        print(f"Using environment ingest resume mode: {configured_resume_mode}.")
        return configured_resume_mode

    while True:
        choice = input(
            "Type 'resume' to skip already-ingested docs or 'start' to process everything again [resume/start]: "
        ).strip().lower()
        if choice in {"", "resume", "start"}:
            return choice
        print("Please enter 'resume' or 'start'.")


def save_resume_progress(path: Path, doc_ids: set[str], metadata: dict[str, Any]) -> None:
    """Persist completed document ids without failing the whole ingest run."""

    try:
        save_resume_state_payload(path=path, doc_ids=doc_ids, metadata=metadata, updated_at=utc_now_iso())
    except Exception as exc:
        print(f"Warning: failed to persist resume state to {path}: {exc}", file=sys.stderr)


__all__ = [
    "ResumePlan",
    "build_resume_key_parts",
    "build_resume_metadata",
    "choose_resume_choice",
    "prepare_resume_plan",
    "save_resume_progress",
]
