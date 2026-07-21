"""Pre-conversion deduplication contracts for uploads, crawls, and retries."""

from __future__ import annotations

import hashlib
import json
from contextlib import nullcontext
from dataclasses import asdict
from datetime import UTC, datetime, timedelta
from pathlib import Path
from tempfile import TemporaryDirectory
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from temporal_rag.deduplication import (
    ClaimedSourceDocument,
    DECISION_DUPLICATE,
    DECISION_NEW,
    DECISION_UPDATED,
    DeduplicationClaimConflictError,
    SourceDeduplicationPlan,
    SourceDeduplicationStore,
    discover_source_documents,
    read_plan,
    write_plan,
)


class _Cursor:
    def __init__(self, row: tuple[object, ...]) -> None:
        self.row = row
        self.executed: list[tuple[str, tuple[object, ...]]] = []
        self.rowcount = 1

    def execute(self, sql: str, params: tuple[object, ...]) -> None:
        self.executed.append((" ".join(sql.split()), params))

    def fetchone(self) -> tuple[object, ...]:
        return self.row


class _Connection:
    def __init__(self, cursor: _Cursor) -> None:
        self._cursor = cursor

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, traceback) -> None:
        return None

    def transaction(self):
        return nullcontext()

    def cursor(self):
        return nullcontext(self._cursor)


class TemporalDeduplicationTests(unittest.TestCase):
    """Verify byte hashes and identity/version decisions happen before conversion."""

    def test_upload_uses_actual_bytes_and_stable_logical_document_id(self) -> None:
        with TemporaryDirectory() as tmp:
            raw_dir = Path(tmp) / "raw"
            raw_dir.mkdir()
            source = raw_dir / "upload.pdf"
            source.write_bytes(b"same upload bytes")
            content_hash = hashlib.sha256(source.read_bytes()).hexdigest()
            workflow_input = _workflow_input(
                raw_dir,
                doc_id="adoc_upload_1",
                upload={"content_hash": content_hash},
                content_hash=content_hash,
            )

            documents = discover_source_documents(
                workflow_input,
                {"raw_dir": str(raw_dir)},
            )

        self.assertEqual(len(documents), 1)
        self.assertEqual(documents[0].document_id, "adoc_upload_1")
        self.assertEqual(documents[0].content_hash, content_hash)

    def test_upload_hashing_reports_progress_before_discovery_finishes(self) -> None:
        with TemporaryDirectory() as tmp:
            raw_dir = Path(tmp) / "raw"
            raw_dir.mkdir()
            source = raw_dir / "large.pdf"
            source.write_bytes(b"x" * (1024 * 1024 + 17))
            content_hash = hashlib.sha256(source.read_bytes()).hexdigest()
            progress: list[tuple[Path, int]] = []

            documents = discover_source_documents(
                _workflow_input(
                    raw_dir,
                    doc_id="adoc_progress",
                    upload={"content_hash": content_hash},
                    content_hash=content_hash,
                ),
                {"raw_dir": str(raw_dir)},
                progress_callback=lambda path, bytes_read: progress.append((path, bytes_read)),
            )

        self.assertEqual(documents[0].content_hash, content_hash)
        self.assertGreaterEqual(len(progress), 2)
        self.assertEqual(progress[-1][1], 1024 * 1024 + 17)

    def test_upload_checksum_mismatch_fails_closed(self) -> None:
        with TemporaryDirectory() as tmp:
            raw_dir = Path(tmp) / "raw"
            raw_dir.mkdir()
            (raw_dir / "upload.pdf").write_bytes(b"corrupt upload bytes")
            workflow_input = _workflow_input(
                raw_dir,
                doc_id="adoc_upload_corrupt",
                upload={"content_hash": "a" * 64},
                content_hash="a" * 64,
            )

            with self.assertRaisesRegex(RuntimeError, "hash mismatch"):
                discover_source_documents(workflow_input, {"raw_dir": str(raw_dir)})

    def test_crawler_manifest_uses_source_url_and_verifies_content_hash(self) -> None:
        with TemporaryDirectory() as tmp:
            raw_dir = Path(tmp) / "raw"
            page_dir = raw_dir / "pages" / "a"
            page_dir.mkdir(parents=True)
            content = page_dir / "content.md"
            content.write_text("# Research\n\nVersion one.", encoding="utf-8")
            content_hash = hashlib.sha256(content.read_bytes()).hexdigest()
            (page_dir / "data.json").write_text('{"metadata":true}', encoding="utf-8")
            (raw_dir / "completed_urls.json").write_text(
                json.dumps({
                    "job_id": "crawl-1",
                    "records": [{
                        "page_id": hashlib.sha256(b"https://example.test/research").hexdigest(),
                        "source_url": "HTTPS://EXAMPLE.TEST:443/research/#fragment",
                        "url": "https://example.test/research/",
                        "canonical_url": "https://example.test/en/research",
                        "content_hash": content_hash,
                        "content_path": "pages/a/content.md",
                        "metadata_path": "pages/a/data.json",
                    }],
                }),
                encoding="utf-8",
            )
            workflow_input = _workflow_input(raw_dir, doc_id="source_parent")

            first = discover_source_documents(workflow_input, {"raw_dir": str(raw_dir)})
            content.write_text("# Research\n\nVersion two.", encoding="utf-8")
            updated_hash = hashlib.sha256(content.read_bytes()).hexdigest()
            manifest = json.loads((raw_dir / "completed_urls.json").read_text(encoding="utf-8"))
            manifest["records"][0]["content_hash"] = updated_hash
            (raw_dir / "completed_urls.json").write_text(json.dumps(manifest), encoding="utf-8")
            second = discover_source_documents(workflow_input, {"raw_dir": str(raw_dir)})

        self.assertEqual(len(first), 1)
        self.assertEqual(first[0].document_id, second[0].document_id)
        self.assertNotEqual(first[0].content_hash, second[0].content_hash)
        self.assertEqual(first[0].source_url, "https://example.test/research")
        self.assertEqual(first[0].canonical_url, "https://example.test/en/research")
        self.assertTrue(first[0].source_path.endswith("content.md"))
        self.assertNotIn("data.json", first[0].source_path)

    def test_crawler_manifest_rejects_paths_outside_raw_directory(self) -> None:
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            raw_dir = root / "raw"
            raw_dir.mkdir()
            outside = root / "outside.md"
            outside.write_text("outside", encoding="utf-8")
            (raw_dir / "completed_urls.json").write_text(
                json.dumps({
                    "records": [{
                        "source_url": "https://example.test/outside",
                        "content_path": "../outside.md",
                        "content_hash": hashlib.sha256(outside.read_bytes()).hexdigest(),
                    }],
                }),
                encoding="utf-8",
            )

            with self.assertRaisesRegex(RuntimeError, "escaped its raw directory"):
                discover_source_documents(
                    _workflow_input(raw_dir, doc_id="source_parent"),
                    {"raw_dir": str(raw_dir)},
                )

    def test_claim_classifies_new_updated_duplicate_and_same_owner_resume(self) -> None:
        now = datetime.now(UTC)
        fingerprint = _fingerprint()
        store = SourceDeduplicationStore(SimpleNamespace())

        new = store._claim_one(  # noqa: SLF001 - focused adapter contract test
            _Cursor((None, None, "pending", None, None, None, None)),
            fingerprint,
            claim_token="run-1",
            task_id="task-1",
            job_id="job-1",
            checked_at=now,
            force=False,
        )
        updated = store._claim_one(  # noqa: SLF001
            _Cursor(("b" * 64, None, "completed", "new", None, now, None)),
            fingerprint,
            claim_token="run-2",
            task_id="task-1",
            job_id="job-1",
            checked_at=now,
            force=False,
        )
        duplicate = store._claim_one(  # noqa: SLF001
            _Cursor((fingerprint.content_hash, None, "completed", "new", None, now, None)),
            fingerprint,
            claim_token="run-3",
            task_id="task-1",
            job_id="job-1",
            checked_at=now,
            force=False,
        )
        resumed = store._claim_one(  # noqa: SLF001
            _Cursor((None, fingerprint.content_hash, "processing", "new", "run-1", now, now + timedelta(hours=1))),
            fingerprint,
            claim_token="run-1",
            task_id="task-1",
            job_id="job-1",
            checked_at=now,
            force=False,
        )

        self.assertEqual(new.decision, DECISION_NEW)
        self.assertEqual(updated.decision, DECISION_UPDATED)
        self.assertEqual(duplicate.decision, DECISION_DUPLICATE)
        self.assertEqual(resumed.decision, DECISION_NEW)

    def test_active_other_owner_is_rejected_until_its_lease_expires(self) -> None:
        now = datetime.now(UTC)
        row = (None, "a" * 64, "processing", "new", "other-run", now, now + timedelta(hours=1))
        fingerprint = _fingerprint()
        store = SourceDeduplicationStore(SimpleNamespace())

        with self.assertRaises(DeduplicationClaimConflictError):
            store._claim_one(  # noqa: SLF001
                _Cursor(row),
                fingerprint,
                claim_token="retry-run",
                task_id="task-1",
                job_id="job-1",
                checked_at=now,
                force=False,
            )

        expired_row = (*row[:-1], now - timedelta(seconds=1))
        reclaimed = store._claim_one(  # noqa: SLF001
            _Cursor(expired_row),
            fingerprint,
            claim_token="retry-run",
            task_id="task-1",
            job_id="job-1",
            checked_at=now,
            force=False,
        )
        self.assertEqual(reclaimed.decision, DECISION_NEW)

    def test_active_update_claim_cannot_be_overwritten_by_old_completed_content(self) -> None:
        now = datetime.now(UTC)
        fingerprint = _fingerprint()
        row = (
            fingerprint.content_hash,
            "b" * 64,
            "processing",
            "updated",
            "updating-run",
            now,
            now + timedelta(hours=1),
        )

        with self.assertRaises(DeduplicationClaimConflictError):
            SourceDeduplicationStore(SimpleNamespace())._claim_one(  # noqa: SLF001
                _Cursor(row),
                fingerprint,
                claim_token="old-content-run",
                task_id="task-1",
                job_id="job-1",
                checked_at=now,
                force=False,
            )

    def test_naive_database_lease_timestamps_are_treated_as_utc(self) -> None:
        now = datetime.now(UTC)
        naive_checked_at = (now - timedelta(hours=25)).replace(tzinfo=None)
        naive_expired_lease = (now - timedelta(minutes=1)).replace(tzinfo=None)
        row = (None, "b" * 64, "processing", "new", "stale-run", naive_checked_at, naive_expired_lease)

        reclaimed = SourceDeduplicationStore(SimpleNamespace())._claim_one(  # noqa: SLF001
            _Cursor(row),
            _fingerprint(),
            claim_token="current-run",
            task_id="task-1",
            job_id="job-1",
            checked_at=now,
            force=False,
        )

        self.assertEqual(reclaimed.decision, DECISION_NEW)

    def test_resume_result_fences_active_claim_and_reuses_committed_result(self) -> None:
        now = datetime.now(UTC)
        fingerprint = _fingerprint()
        store = SourceDeduplicationStore(SimpleNamespace())
        claimed = store._claim_one(  # noqa: SLF001
            _Cursor((None, None, "pending", None, None, None, None)),
            fingerprint,
            claim_token="run-resume",
            task_id="task-1",
            job_id="job-1",
            checked_at=now,
            force=False,
        )
        plan = SourceDeduplicationPlan(
            claim_token="run-resume",
            scope_key=fingerprint.scope_key,
            source_id=fingerprint.source_id,
            documents=(claimed,),
            created_at=now.isoformat(),
        )

        active_cursor = _Cursor((None, fingerprint.content_hash, "processing", "run-resume", {}))
        with patch.object(store, "connection", return_value=_Connection(active_cursor)):
            self.assertIsNone(store.resume_result(plan))

        committed = {"status": "success", "documents_indexed": 1, "chunks_indexed": 3}
        completed_cursor = _Cursor((
            fingerprint.content_hash,
            None,
            "completed",
            None,
            json.dumps({"ingest_result": committed}),
        ))
        with patch.object(store, "connection", return_value=_Connection(completed_cursor)):
            self.assertEqual(store.resume_result(plan), committed)

        foreign_cursor = _Cursor((None, fingerprint.content_hash, "processing", "other-run", {}))
        with patch.object(store, "connection", return_value=_Connection(foreign_cursor)):
            with self.assertRaises(DeduplicationClaimConflictError):
                store.resume_result(plan)

    def test_plan_is_atomic_disk_handoff_with_aggregate_counts(self) -> None:
        now = datetime.now(UTC).isoformat()
        fingerprint = _fingerprint()
        store = SourceDeduplicationStore(SimpleNamespace())
        claimed_new = store._claim_one(  # noqa: SLF001
            _Cursor((None, None, "pending", None, None, None, None)),
            fingerprint,
            claim_token="run-plan",
            task_id="task-plan",
            job_id="job-plan",
            checked_at=datetime.now(UTC),
            force=False,
        )
        duplicate_fingerprint = _fingerprint(document_id="doc_duplicate", content_hash="b" * 64)
        claimed_duplicate = store._claim_one(  # noqa: SLF001
            _Cursor(("b" * 64, None, "completed", "new", None, None, None)),
            duplicate_fingerprint,
            claim_token="run-plan",
            task_id="task-plan",
            job_id="job-plan",
            checked_at=datetime.now(UTC),
            force=False,
        )
        plan = SourceDeduplicationPlan(
            claim_token="run-plan",
            scope_key="collection-a",
            source_id="source-a",
            documents=(claimed_new, claimed_duplicate),
            created_at=now,
        )

        with TemporaryDirectory() as tmp:
            raw_dir = Path(tmp) / "raw"
            raw_dir.mkdir()
            plan_path = write_plan(plan, str(raw_dir))
            restored = read_plan(plan_path)

        payload = restored.to_payload(plan_path)
        self.assertEqual(payload["new_documents"], 1)
        self.assertEqual(payload["duplicate_documents"], 1)
        self.assertEqual(payload["process_documents"], 1)
        self.assertEqual(payload["decision"], "mixed")
        self.assertFalse(payload["skip_processing"])

    def test_force_reprocess_treats_matching_completed_hash_as_updated(self) -> None:
        now = datetime.now(UTC)
        fingerprint = _fingerprint()
        store = SourceDeduplicationStore(SimpleNamespace())
        forced = store._claim_one(  # noqa: SLF001
            _Cursor((fingerprint.content_hash, None, "completed", "new", None, now, None)),
            fingerprint,
            claim_token="forced-run",
            task_id="task-1",
            job_id="job-1",
            checked_at=now,
            force=True,
        )
        self.assertEqual(forced.decision, DECISION_UPDATED)

    def test_classifier_logs_duplicate_skip_and_fails_closed_on_store_error(self) -> None:
        from temporal_rag.activity_deduplicate import classify_source_documents

        fingerprint = _fingerprint()
        claimed_duplicate = ClaimedSourceDocument(
            **asdict(fingerprint),
            decision=DECISION_DUPLICATE,
            previous_content_hash=fingerprint.content_hash,
        )
        payload = {
            "workflow_input": {
                "source_id": "source-a",
                "task_id": "task-a",
                "job_id": "job-a",
                "raw_output_path": "/shared/source-a/raw",
                "deduplication": {"force": False},
            },
            "scrape_result": {"raw_dir": "/shared/source-a/raw"},
        }

        with (
            patch("temporal_rag.activity_deduplicate.TemporalRagSettings.from_env", return_value=SimpleNamespace()),
            patch("temporal_rag.activity_deduplicate.AppMetadataStore"),
            patch("temporal_rag.activity_deduplicate.discover_source_documents", return_value=[fingerprint]),
            patch("temporal_rag.activity_deduplicate.SourceDeduplicationStore") as store_type,
            patch("temporal_rag.activity_deduplicate.write_plan", return_value="/shared/plan.json"),
            patch("temporal_rag.activity_deduplicate.log_event") as event_log,
        ):
            store_type.return_value.claim.return_value = [claimed_duplicate]
            result = classify_source_documents(payload)

        decision_call = next(
            call
            for call in event_log.call_args_list
            if len(call.args) > 1 and call.args[1] == "dedup:decision"
        )
        self.assertEqual(decision_call.kwargs["doc_id"], fingerprint.document_id)
        self.assertEqual(decision_call.kwargs["content_hash"], fingerprint.content_hash)
        self.assertEqual(decision_call.kwargs["decision"], DECISION_DUPLICATE)
        self.assertEqual(decision_call.kwargs["reason"], "same_doc_id_and_content_hash")
        self.assertEqual(decision_call.kwargs["skip_action"], "conversion_and_ingestion")
        self.assertTrue(result["skip_processing"])

        with (
            patch("temporal_rag.activity_deduplicate.TemporalRagSettings.from_env", return_value=SimpleNamespace()),
            patch("temporal_rag.activity_deduplicate.AppMetadataStore"),
            patch("temporal_rag.activity_deduplicate.discover_source_documents", return_value=[fingerprint]),
            patch("temporal_rag.activity_deduplicate.SourceDeduplicationStore") as failed_store,
            patch("temporal_rag.activity_deduplicate.write_plan") as write_plan_mock,
        ):
            failed_store.return_value.claim.side_effect = ConnectionError("postgres unavailable")
            with self.assertRaisesRegex(ConnectionError, "postgres unavailable"):
                classify_source_documents(payload)
            write_plan_mock.assert_not_called()


def _workflow_input(
    raw_dir: Path,
    *,
    doc_id: str,
    upload: dict[str, str] | None = None,
    content_hash: str | None = None,
) -> dict[str, object]:
    deduplication: dict[str, object] = {
        "scope_key": "collection-a",
        "doc_id": doc_id,
        "force": False,
    }
    if content_hash is not None:
        deduplication["content_hash"] = content_hash
    payload: dict[str, object] = {
        "source_id": "source-a",
        "source_url": "upload://sample.pdf" if upload else "https://example.test",
        "raw_output_path": str(raw_dir),
        "deduplication": deduplication,
    }
    if upload is not None:
        payload["upload"] = upload
    return payload


def _fingerprint(
    *,
    document_id: str = "doc_a",
    content_hash: str = "a" * 64,
):
    from temporal_rag.deduplication import SourceDocumentFingerprint

    return SourceDocumentFingerprint(
        scope_key="collection-a",
        document_id=document_id,
        content_hash=content_hash,
        source_id="source-a",
        source_path="/shared/source-a/content.md",
        relative_path="content.md",
        source_url="https://example.test/a",
    )


if __name__ == "__main__":
    unittest.main()
