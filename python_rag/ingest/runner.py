"""Reusable crawler ingestion orchestration for ingest_crawled."""
from __future__ import annotations

import json
import os
import sys
from pathlib import Path
from typing import Any, Dict, List, Optional, Set

try:
    from ingest.discovery import discover_page_dirs
    from ingest.estimation import run_local_estimate, utc_now_iso
    from ingest.materials import load_page_materials
    from ingest.metadata import (
        first_str,
        make_doc_id,
        resolve_date,
        resolve_tags,
        title_from_markdown,
        to_array_list,
    )
    from ingest.payloads import build_bridge_doc, build_payload
    from ingest.resume import load_resume_state, safe_state_filename, save_resume_state_payload, should_split_batch
    from ingest.url_maps import build_url_maps, resolve_url_for_path
    from ingest.links import extract_pdf_links
except ImportError:
    from discovery import discover_page_dirs
    from estimation import run_local_estimate, utc_now_iso
    from materials import load_page_materials
    from metadata import (
        first_str,
        make_doc_id,
        resolve_date,
        resolve_tags,
        title_from_markdown,
        to_array_list,
    )
    from payloads import build_bridge_doc, build_payload
    from resume import load_resume_state, safe_state_filename, save_resume_state_payload, should_split_batch
    from url_maps import build_url_maps, resolve_url_for_path
    from links import extract_pdf_links

EXIT_RUNTIME_FAILURE = 1
EXIT_VALIDATION_FAILURE = 2
EXIT_PARTIAL_SUCCESS = 3


def _env_bool(name: str, default: bool = False) -> bool:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    return str(raw).strip().lower() in {"1", "true", "yes", "on"}


def _env_choice(name: str, allowed: Set[str], default: str) -> str:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    value = str(raw).strip().lower()
    if value not in allowed:
        raise ValueError(
            f"Invalid {name}={raw!r}; expected one of: {', '.join(sorted(allowed))}."
        )
    return value


def _save_resume_state(path: Path, doc_ids: Set[str], metadata: Dict[str, Any]) -> None:
    try:
        save_resume_state_payload(path=path, doc_ids=doc_ids, metadata=metadata, updated_at=utc_now_iso())
    except Exception as exc:
        print(f"Warning: failed to persist resume state to {path}: {exc}", file=sys.stderr)


def _build_default_options(args: Any) -> Dict[str, Any]:
    options: Dict[str, Any] = {
        "provider": args.provider,
        "graph": bool(args.graph),
        "graph_engine": args.graph_engine,
        "distance": args.distance,
        "chunk_chars": int(args.chunk_chars),
        "chunk_overlap": int(args.chunk_overlap),
        "batch_size": int(args.batch),
    }
    if args.graph_model:
        options["graph_model"] = args.graph_model
    if args.graph_only:
        options["graph_only"] = True
    if args.neo4j_database:
        options["neo4j_database"] = args.neo4j_database
    if args.embedding_model:
        options["embedding_model"] = args.embedding_model
    if args.collection:
        options["collection"] = args.collection
    if args.dry:
        options["dry_run"] = True
        if args.dry_include_graph:
            options["dry_include_graph"] = True
    return options


def _build_no_pages_summary(summary_file: Optional[str]) -> Dict[str, Any]:
    return {
        "timestamp": utc_now_iso(),
        "estimate_only": False,
        "status": "partial",
        "reason": "no_pages_found",
        "documents": {
            "total_docs": 0,
            "processed_docs": 0,
            "skipped_docs": 0,
            "empty_docs": 0,
            "doc_ids": [],
            "total_chunks": 0,
        },
        "summary_file": summary_file,
    }


def _build_no_ingestable_summary(total_dirs: int, skipped_empty: int, skipped_empty_paths: list[str], summary_file: Optional[str]) -> Dict[str, Any]:
    return {
        "timestamp": utc_now_iso(),
        "estimate_only": False,
        "status": "partial",
        "reason": "no_ingestable_documents",
        "documents": {
            "total_docs": total_dirs,
            "processed_docs": 0,
            "skipped_docs": skipped_empty,
            "empty_docs": skipped_empty,
            "empty_paths": skipped_empty_paths,
            "doc_ids": [],
            "total_chunks": 0,
        },
        "summary_file": summary_file,
    }


def run_ingest(args: Any) -> int:
    try:
        from ingest.submit import post_batch, write_summary_file
    except ImportError:
        try:
            from submit import post_batch, write_summary_file
        except Exception as exc:
            raise RuntimeError(
                "This script requires 'requests'. Install with: pip install requests"
            ) from exc

    if args.resume and args.start:
        print("Choose only one of --resume or --start.", file=sys.stderr)
        return EXIT_VALIDATION_FAILURE

    automation_mode = _env_bool("HAWKI_RAG_PIPELINE_AUTOMATION", False)
    try:
        configured_resume_mode = _env_choice("HAWKI_RAG_INGEST_RESUME_MODE", {"resume", "start", "ask"}, "resume")
    except ValueError as exc:
        print(str(exc), file=sys.stderr)
        return EXIT_VALIDATION_FAILURE

    root = Path(args.root).expanduser().resolve()
    if not root.exists() or not root.is_dir():
        print(f"Root not found or not a directory: {root}", file=sys.stderr)
        return EXIT_VALIDATION_FAILURE

    if not args.collection:
        args.collection = root.name

    resume_doc_ids: Set[str] = set()
    resume_state_path: Optional[Path] = None
    resume_metadata: Dict[str, Any] = {}
    resume_mode = False

    state_dir = Path(args.resume_state_dir).expanduser().resolve()
    resume_key_parts = [args.collection or "default", str(root), args.base_url.rstrip("/")]
    if args.graph_only:
        resume_key_parts.append("graph_only")
    if args.neo4j_database:
        resume_key_parts.append(f"neo4j_db={args.neo4j_database}")
    resume_key = "::".join(resume_key_parts)

    if not args.dry and not args.estimate_only:
        resume_state_path = state_dir / safe_state_filename(resume_key)
        existing_ids = load_resume_state(resume_state_path)
        resume_metadata = {
            "collection": args.collection,
            "root": str(root),
            "base_url": args.base_url,
            "graph_only": bool(args.graph_only),
            "graph": bool(args.graph),
            "neo4j_database": args.neo4j_database or None,
        }

        if existing_ids:
            print(f"Found previous ingest state for '{resume_key_parts[0]}' with {len(existing_ids)} documents.")
            if args.resume:
                choice = "resume"
            elif args.start:
                choice = "start"
            elif automation_mode or not sys.stdin.isatty():
                choice = configured_resume_mode
                if choice == "ask":
                    choice = "resume"
                print(f"Automation/non-interactive mode selected ingest resume mode: {choice}.")
            elif configured_resume_mode != "ask":
                choice = configured_resume_mode
                print(f"Using environment ingest resume mode: {choice}.")
            else:
                while True:
                    choice = input(
                        "Type 'resume' to skip already-ingested docs or 'start' to process everything again [resume/start]: "
                    ).strip().lower()
                    if choice in {"", "resume", "start"}:
                        break
                    print("Please enter 'resume' or 'start'.")

            if choice in {"", "resume"}:
                resume_mode = True
                resume_doc_ids = existing_ids
                print(f"Resuming ingest; skipping {len(resume_doc_ids)} documents already processed.")
            else:
                resume_mode = False
                resume_doc_ids = set()
                try:
                    resume_state_path.unlink(missing_ok=True)
                except Exception as exc:
                    print(f"Warning: failed to remove existing resume state: {exc}", file=sys.stderr)
                print("Starting fresh; previous state will be replaced.")
        else:
            print(f"No previous ingest state found for '{resume_key_parts[0]}'. Starting fresh.")

    page_url_map, source_url_map = build_url_maps(root)
    page_dirs = discover_page_dirs(root)

    if not page_dirs:
        print("No pages found under root.")
        summary = _build_no_pages_summary(summary_file=args.summary_file)
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

    options = _build_default_options(args)

    docs: list[Dict[str, Any]] = []
    total = 0
    sent = 0
    batch_index = 0
    last_response: Optional[Dict[str, Any]] = None
    skipped_existing = 0
    skipped_empty = 0
    skipped_empty_paths: list[str] = []
    processed_doc_ids: Set[str] = set(resume_doc_ids)
    failed_batches = 0

    total_dirs = len(page_dirs)
    print(f"Scanning: {root}")
    if args.dry:
        print("Running in dry-run mode; embeddings and database writes are skipped.")

    print(f"Discovered {total_dirs} page folders.")
    min_split_batch = int(os.environ.get("INGEST_MIN_BATCH", "4"))
    max_split_depth = int(os.environ.get("INGEST_MAX_SPLITS", "4"))

    def _send_batch(docs_batch: list[Dict[str, Any]], depth: int = 0) -> bool:
        nonlocal batch_index, sent, last_response, processed_doc_ids, failed_batches
        if not docs_batch:
            return True

        batch_index += 1
        doc_ids_batch = [doc.get("id") for doc in docs_batch]
        ok, data, err = post_batch(base_url=args.base_url, docs=docs_batch, options=options, timeout=args.timeout)
        if ok:
            sent += len(docs_batch)
            if args.dry:
                print(f"Planned {sent}/{total} docs… (batch {batch_index})")
            else:
                print(f"Sent {sent}/{total} docs… (batch {batch_index})")
            if data:
                last_response = data
            if not args.dry and resume_state_path is not None:
                processed_doc_ids.update(str(doc.get("id")) for doc in docs_batch if doc.get("id"))
                _save_resume_state(resume_state_path, processed_doc_ids, resume_metadata)
            return True

        if should_split_batch(err) and len(docs_batch) > max(1, min_split_batch) and depth < max_split_depth:
            mid = max(1, len(docs_batch) // 2)
            left = docs_batch[:mid]
            right = docs_batch[mid:]
            print(
                f"Batch {batch_index} failed; splitting {len(docs_batch)} into {len(left)} + {len(right)} due to timeout/5xx.",
                file=sys.stderr,
            )
            left_ok = _send_batch(left, depth=depth + 1)
            right_ok = _send_batch(right, depth=depth + 1)
            return left_ok and right_ok

        print(f"Batch {batch_index} failed; docs={doc_ids_batch} ({err or 'see log'})", file=sys.stderr)
        failed_batches += 1
        return False

    for directory in page_dirs:
        rel_dir = str(directory.relative_to(root))
        print(f"Folder {directory.relative_to(root)}")
        meta, md_path, json_path, text, source_fmt = load_page_materials(directory)

        if not isinstance(text, str) or text.strip() == "":
            skipped_empty += 1
            skipped_empty_paths.append(rel_dir)
            print(f"Skipped empty page folder: {rel_dir}", file=sys.stderr)
            continue

        title = first_str(meta.get("title")) or (title_from_markdown(text) or "Untitled")
        dir_resolved = directory.resolve(strict=False)
        page_url = first_str(meta.get("url") or meta.get("page_url")) or resolve_url_for_path(
            mapping=page_url_map,
            path=dir_resolved,
            root=root,
        )
        source_path = md_path or json_path or directory
        date = resolve_date(meta, source_path)
        meta_img = first_str(meta.get("metaImageUrl") or meta.get("meta_img_url"))
        images_list = to_array_list(meta.get("images"))
        pdfs_list = to_array_list(meta.get("pdfs"))
        if not pdfs_list:
            pdfs_list = extract_pdf_links(text)
        tags = resolve_tags(meta, text)
        rel = str(directory.relative_to(root))
        source_url = first_str(meta.get("source_url")) or resolve_url_for_path(
            mapping=source_url_map,
            path=dir_resolved,
            root=root,
        )
        if not source_url and page_url:
            source_url = page_url

        doc_id = make_doc_id(source_url if source_url and source_url != page_url else page_url, rel)
        if resume_mode and doc_id in resume_doc_ids:
            skipped_existing += 1
            continue

        payload = build_payload(
            meta=meta,
            title=title,
            page_url=page_url,
            source_url=source_url,
            rel_path=rel,
            date=date,
            meta_img=meta_img,
            meta_img_list=to_array_list(meta.get("meta_img_url") or meta.get("metaImageUrl")),
            images_list=images_list,
            pdfs_list=pdfs_list,
            tags=tags,
            source_format=source_fmt,
            md_path=md_path,
            ingested_at=utc_now_iso(),
        )

        docs.append(build_bridge_doc(doc_id=doc_id, text=text, payload=payload))
        total += 1

        if len(docs) >= args.batch:
            _send_batch(docs, depth=0)
            docs = []

    if docs:
        _send_batch(docs, depth=0)
        if args.dry:
            print(f"Planned {sent}/{total} docs. Dry run complete.")
        else:
            print(f"Sent {sent}/{total} docs. Done.")

    if skipped_empty:
        print(f"Skipped empty page folders: {skipped_empty}", file=sys.stderr)

    if total == 0:
        print("No ingestable documents found after skipping empty page folders.", file=sys.stderr)
        summary = _build_no_ingestable_summary(total_dirs, skipped_empty, skipped_empty_paths, args.summary_file)
        if args.summary_file:
            write_summary_file(args.summary_file, summary)
        return EXIT_PARTIAL_SUCCESS

    if args.dry and last_response:
        summary = last_response.get("summary") or {}
        planned_points = summary.get("planned_points")
        if planned_points is not None:
            print(f"[dry-run] Estimated Qdrant points: {planned_points}")
        graph_preview = summary.get("graph_preview") or {}
        if graph_preview:
            planned_entities = graph_preview.get("planned_entities")
            planned_triplets = graph_preview.get("planned_triplets")
            if planned_entities is not None:
                print(f"[dry-run] Estimated Neo4j entities: {planned_entities}")
            if planned_triplets is not None:
                print(f"[dry-run] Estimated Neo4j relationships: {planned_triplets}")

    if args.summary_file and last_response:
        summary = last_response.get("summary")
        if summary:
            write_summary_file(args.summary_file, summary)

    if resume_state_path is not None:
        if not args.dry:
            print(f"Resume state stored at {resume_state_path}")
        if resume_mode and skipped_existing:
            print(f"Skipped {skipped_existing} documents already ingested earlier.")

    if not args.dry and not args.estimate_only:
        if failed_batches:
            return EXIT_RUNTIME_FAILURE
    if skipped_empty and sent == 0:
        return EXIT_PARTIAL_SUCCESS

    return 0
