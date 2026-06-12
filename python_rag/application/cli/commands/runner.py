"""Reusable crawler ingestion orchestration for ingest_crawled."""
from __future__ import annotations

import json
import os
import sys
from pathlib import Path
from typing import Any

from application.cli.commands.discovery import discover_page_dirs
from application.cli.commands.estimation import run_local_estimate
from application.cli.commands.runner_batches import BatchSender
from application.cli.commands.runner_config import (
    build_default_options,
    build_no_ingestable_summary,
    build_no_pages_summary,
    env_bool,
    env_choice,
)
from application.cli.commands.runner_completion import (
    determine_exit_code,
    report_dry_run_summary,
    report_resume_state,
    write_last_summary,
)
from application.cli.commands.runner_pages import build_page_document
from application.cli.commands.runner_resume import prepare_resume_plan
from application.cli.commands.url_maps import build_url_maps

EXIT_RUNTIME_FAILURE = 1
EXIT_VALIDATION_FAILURE = 2
EXIT_PARTIAL_SUCCESS = 3


def run_ingest(args: Any) -> int:
    from application.cli.commands.submit import post_batch, write_summary_file

    if args.resume and args.start:
        print("Choose only one of --resume or --start.", file=sys.stderr)
        return EXIT_VALIDATION_FAILURE

    automation_mode = env_bool("HAWKI_RAG_PIPELINE_AUTOMATION", False)
    try:
        configured_resume_mode = env_choice("HAWKI_RAG_INGEST_RESUME_MODE", {"resume", "start", "ask"}, "resume")
    except ValueError as exc:
        print(str(exc), file=sys.stderr)
        return EXIT_VALIDATION_FAILURE

    root = Path(args.root).expanduser().resolve()
    if not root.exists() or not root.is_dir():
        print(f"Root not found or not a directory: {root}", file=sys.stderr)
        return EXIT_VALIDATION_FAILURE

    if not args.collection:
        args.collection = root.name

    resume_plan = prepare_resume_plan(
        args,
        root,
        automation_mode=automation_mode,
        configured_resume_mode=configured_resume_mode,
    )
    resume_doc_ids = resume_plan.doc_ids
    resume_state_path = resume_plan.state_path
    resume_mode = resume_plan.resume_mode

    page_url_map, source_url_map = build_url_maps(root)
    page_dirs = discover_page_dirs(root)

    if not page_dirs:
        print("No pages found under root.")
        summary = build_no_pages_summary(summary_file=args.summary_file)
        if args.summary_file:
            write_summary_file(args.summary_file, summary)
        return EXIT_PARTIAL_SUCCESS

    if args.estimate_only:
        print(f"Scanning: {root}")
        print("Running local estimate; server is not contacted.")
        summary = run_local_estimate(
            page_dirs=page_dirs,
            root=root,
            chunk_chars=args.chunk_chars,
            chunk_overlap=args.chunk_overlap,
            collection=args.collection,
            batch_size=args.batch,
        )
        preview = json.dumps(summary, indent=2, ensure_ascii=False)
        print(preview)
        if args.summary_file:
            out_path = Path(args.summary_file).expanduser().resolve()
            out_path.parent.mkdir(parents=True, exist_ok=True)
            out_path.write_text(preview + "\n", encoding="utf-8")
            print(f"Saved estimate summary to {out_path}")
        return 0

    options = build_default_options(args)

    docs: list[dict[str, Any]] = []
    total = 0
    skipped_existing = 0
    skipped_empty = 0
    skipped_empty_paths: list[str] = []

    total_dirs = len(page_dirs)
    print(f"Scanning: {root}")
    if args.dry:
        print("Running in dry-run mode; embeddings and database writes are skipped.")

    print(f"Discovered {total_dirs} page folders.")
    min_split_batch = int(os.environ.get("INGEST_MIN_BATCH", "4"))
    max_split_depth = int(os.environ.get("INGEST_MAX_SPLITS", "4"))
    batch_sender = BatchSender(
        args=args,
        options=options,
        resume_state_path=resume_state_path,
        resume_metadata=resume_plan.metadata,
        processed_doc_ids=set(resume_doc_ids),
        post_batch=post_batch,
        min_split_batch=min_split_batch,
        max_split_depth=max_split_depth,
    )

    for directory in page_dirs:
        print(f"Folder {directory.relative_to(root)}")
        page_doc = build_page_document(
            directory=directory,
            root=root,
            page_url_map=page_url_map,
            source_url_map=source_url_map,
        )

        if page_doc.empty:
            skipped_empty += 1
            skipped_empty_paths.append(page_doc.rel_dir)
            print(f"Skipped empty page folder: {page_doc.rel_dir}", file=sys.stderr)
            continue

        doc_id = page_doc.doc_id or ""
        if resume_mode and doc_id in resume_doc_ids:
            skipped_existing += 1
            continue

        if page_doc.doc is None:
            skipped_empty += 1
            skipped_empty_paths.append(page_doc.rel_dir)
            print(f"Skipped empty page folder: {page_doc.rel_dir}", file=sys.stderr)
            continue

        docs.append(page_doc.doc)
        total += 1

        if len(docs) >= args.batch:
            batch_sender.send(docs, total=total)
            docs = []

    if docs:
        batch_sender.send(docs, total=total)
        if args.dry:
            print(f"Planned {batch_sender.sent}/{total} docs. Dry run complete.")
        else:
            print(f"Sent {batch_sender.sent}/{total} docs. Done.")

    if skipped_empty:
        print(f"Skipped empty page folders: {skipped_empty}", file=sys.stderr)

    if total == 0:
        print("No ingestable documents found after skipping empty page folders.", file=sys.stderr)
        summary = build_no_ingestable_summary(total_dirs, skipped_empty, skipped_empty_paths, args.summary_file)
        if args.summary_file:
            write_summary_file(args.summary_file, summary)
        return EXIT_PARTIAL_SUCCESS

    report_dry_run_summary(dry_run=bool(args.dry), last_response=batch_sender.last_response)
    write_last_summary(
        summary_file=args.summary_file,
        last_response=batch_sender.last_response,
        write_summary_file=write_summary_file,
    )
    report_resume_state(
        resume_state_path=resume_state_path,
        dry_run=bool(args.dry),
        resume_mode=resume_mode,
        skipped_existing=skipped_existing,
    )
    return determine_exit_code(
        dry_run=bool(args.dry),
        estimate_only=bool(args.estimate_only),
        failed_batches=batch_sender.failed_batches,
        skipped_empty=skipped_empty,
        sent=batch_sender.sent,
    )
