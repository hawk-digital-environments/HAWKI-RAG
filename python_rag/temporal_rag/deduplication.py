"""Pre-conversion document identity, hashing, claiming, and plan persistence."""

from __future__ import annotations

import hashlib
import hmac
import json
from contextlib import contextmanager
from dataclasses import asdict, dataclass
from datetime import UTC, datetime, timedelta
from pathlib import Path
from typing import Any, Callable, Iterator, Mapping
from urllib.parse import urlsplit, urlunsplit

from temporal_rag.settings import TemporalRagSettings
from temporal_rag.storage import is_object_prefix, list_conversion_input_files


DECISION_NEW = "new"
DECISION_UPDATED = "updated"
DECISION_DUPLICATE = "duplicate"
STATUS_COMPLETED = "completed"
STATUS_FAILED = "failed"
STATUS_PENDING = "pending"
STATUS_PROCESSING = "processing"
CLAIM_STALE_AFTER = timedelta(hours=24)


class DeduplicationClaimConflictError(RuntimeError):
    """Another workflow run currently owns the document processing claim."""


@dataclass(frozen=True, slots=True)
class SourceDocumentFingerprint:
    """Stable source identity and byte-level version before conversion."""

    scope_key: str
    document_id: str
    content_hash: str
    source_id: str
    source_path: str
    relative_path: str
    source_url: str | None = None
    canonical_url: str | None = None
    page_id: str | None = None


@dataclass(frozen=True, slots=True)
class ClaimedSourceDocument:
    """A source fingerprint plus the persistent processing decision."""

    scope_key: str
    document_id: str
    content_hash: str
    source_id: str
    source_path: str
    relative_path: str
    decision: str
    previous_content_hash: str | None
    source_url: str | None = None
    canonical_url: str | None = None
    page_id: str | None = None

    @property
    def requires_processing(self) -> bool:
        return self.decision in {DECISION_NEW, DECISION_UPDATED}


@dataclass(frozen=True, slots=True)
class SourceDeduplicationPlan:
    """Disk-backed plan kept out of Temporal workflow history."""

    claim_token: str
    scope_key: str
    source_id: str
    documents: tuple[ClaimedSourceDocument, ...]
    created_at: str

    @property
    def process_documents(self) -> tuple[ClaimedSourceDocument, ...]:
        return tuple(document for document in self.documents if document.requires_processing)

    @property
    def new_documents(self) -> int:
        return sum(document.decision == DECISION_NEW for document in self.documents)

    @property
    def updated_documents(self) -> int:
        return sum(document.decision == DECISION_UPDATED for document in self.documents)

    @property
    def duplicate_documents(self) -> int:
        return sum(document.decision == DECISION_DUPLICATE for document in self.documents)

    @property
    def document_version(self) -> str:
        digest = hashlib.sha256()
        for document in sorted(self.documents, key=lambda item: item.document_id):
            digest.update(document.document_id.encode("utf-8"))
            digest.update(b"\0")
            digest.update(document.content_hash.encode("ascii"))
            digest.update(b"\0")
        return digest.hexdigest()

    def to_payload(self, plan_path: str) -> dict[str, Any]:
        if self.new_documents and not self.updated_documents and not self.duplicate_documents:
            decision = DECISION_NEW
        elif self.updated_documents and not self.new_documents and not self.duplicate_documents:
            decision = DECISION_UPDATED
        elif self.duplicate_documents and not self.new_documents and not self.updated_documents:
            decision = DECISION_DUPLICATE
        else:
            decision = "mixed"

        return {
            "status": "success",
            "decision": decision,
            "scope_key": self.scope_key,
            "source_id": self.source_id,
            "claim_token": self.claim_token,
            "plan_path": plan_path,
            "document_version": self.document_version,
            "total_documents": len(self.documents),
            "new_documents": self.new_documents,
            "updated_documents": self.updated_documents,
            "duplicate_documents": self.duplicate_documents,
            "process_documents": len(self.process_documents),
            "skip_processing": not self.process_documents,
        }


def discover_source_documents(
    workflow_input: Mapping[str, Any],
    scrape_result: Mapping[str, Any],
    *,
    progress_callback: Callable[[Path, int], None] | None = None,
) -> list[SourceDocumentFingerprint]:
    """Fingerprint upload or crawler documents using stable pre-conversion identities."""

    deduplication = _mapping(workflow_input.get("deduplication"))
    scope_key = _required_string(deduplication.get("scope_key"), "deduplication.scope_key")
    base_document_id = _required_string(deduplication.get("doc_id"), "deduplication.doc_id")
    source_id = _required_string(workflow_input.get("source_id"), "source_id")
    raw_dir = _required_string(
        scrape_result.get("raw_dir") or workflow_input.get("raw_output_path"),
        "raw_output_path",
    )
    raw_root = _local_root(raw_dir)

    upload = _mapping(workflow_input.get("upload"))
    if upload:
        candidates = list_conversion_input_files(raw_dir)
        if len(candidates) != 1:
            raise RuntimeError(
                f"Uploaded source [{source_id}] must resolve to exactly one raw file; found {len(candidates)}."
            )
        candidate = candidates[0]
        actual_hash = sha256_file(candidate, progress_callback=progress_callback)
        expected_hash = _sha256_or_none(deduplication.get("content_hash") or upload.get("content_hash"))
        if expected_hash is not None and not hmac.compare_digest(actual_hash, expected_hash):
            raise RuntimeError(
                f"Uploaded source hash mismatch for [{source_id}]; stored bytes differ from the upload checksum."
            )
        return [
            SourceDocumentFingerprint(
                scope_key=scope_key,
                document_id=base_document_id,
                content_hash=actual_hash,
                source_id=source_id,
                source_path=str(candidate),
                relative_path=candidate.relative_to(raw_root).as_posix(),
                source_url=_optional_string(workflow_input.get("source_url")),
            )
        ]

    manifest_documents = _crawler_manifest_documents(
        raw_root=raw_root,
        scope_key=scope_key,
        base_document_id=base_document_id,
        source_id=source_id,
        progress_callback=progress_callback,
    )
    if manifest_documents:
        return manifest_documents

    candidates = list_conversion_input_files(raw_dir)
    return [
        _fallback_fingerprint(
            path=path,
            raw_root=raw_root,
            scope_key=scope_key,
            base_document_id=base_document_id,
            source_id=source_id,
            progress_callback=progress_callback,
        )
        for path in candidates
    ]


def write_plan(plan: SourceDeduplicationPlan, raw_dir: str) -> str:
    """Persist the detailed plan beside source storage and return its stable path."""

    root = _local_root(raw_dir)
    token_hash = hashlib.sha256(plan.claim_token.encode("utf-8")).hexdigest()[:24]
    plan_path = root.parent / "deduplication" / f"plan-{token_hash}.json"
    plan_path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "claim_token": plan.claim_token,
        "scope_key": plan.scope_key,
        "source_id": plan.source_id,
        "created_at": plan.created_at,
        "documents": [asdict(document) for document in plan.documents],
    }
    temporary_path = plan_path.with_suffix(".tmp")
    temporary_path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    temporary_path.replace(plan_path)
    return str(plan_path)


def read_plan(plan_path: str) -> SourceDeduplicationPlan:
    """Read and validate a plan produced by :func:`write_plan`."""

    path = Path(plan_path.removeprefix("file://")).expanduser().resolve()
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Deduplication plan is unreadable: {path}") from exc
    if not isinstance(payload, dict) or not isinstance(payload.get("documents"), list):
        raise RuntimeError(f"Deduplication plan has an invalid structure: {path}")

    documents: list[ClaimedSourceDocument] = []
    for value in payload["documents"]:
        if not isinstance(value, dict):
            raise RuntimeError(f"Deduplication plan contains a non-object document: {path}")
        documents.append(ClaimedSourceDocument(**value))

    return SourceDeduplicationPlan(
        claim_token=_required_string(payload.get("claim_token"), "claim_token"),
        scope_key=_required_string(payload.get("scope_key"), "scope_key"),
        source_id=_required_string(payload.get("source_id"), "source_id"),
        documents=tuple(documents),
        created_at=_required_string(payload.get("created_at"), "created_at"),
    )


class SourceDeduplicationStore:
    """Atomic claims keyed by scope/document identity with byte hashes as versions."""

    def __init__(self, settings: TemporalRagSettings) -> None:
        self.settings = settings

    @classmethod
    def from_env(cls) -> "SourceDeduplicationStore":
        return cls(TemporalRagSettings.from_env())

    @contextmanager
    def connection(self) -> Iterator[Any]:
        try:
            import psycopg
        except ModuleNotFoundError as exc:
            raise RuntimeError("psycopg is required for document deduplication state.") from exc

        connection = psycopg.connect(
            host=self.settings.db_host,
            port=self.settings.db_port,
            dbname=self.settings.db_name,
            user=self.settings.db_user,
            password=self.settings.db_password,
            options="-c timezone=UTC",
        )
        try:
            yield connection
        finally:
            connection.close()

    def claim(
        self,
        fingerprints: list[SourceDocumentFingerprint],
        *,
        claim_token: str,
        task_id: str | None,
        job_id: str | None,
        force: bool = False,
    ) -> list[ClaimedSourceDocument]:
        if not fingerprints:
            raise RuntimeError("No source documents were available for deduplication.")

        claimed: list[ClaimedSourceDocument] = []
        checked_at = datetime.now(UTC)
        with self.connection() as connection:
            with connection.transaction():
                with connection.cursor() as cursor:
                    for fingerprint in fingerprints:
                        claimed.append(
                            self._claim_one(
                                cursor,
                                fingerprint,
                                claim_token=claim_token,
                                task_id=task_id,
                                job_id=job_id,
                                checked_at=checked_at,
                                force=force,
                            )
                        )
        return claimed

    def _claim_one(
        self,
        cursor: Any,
        fingerprint: SourceDocumentFingerprint,
        *,
        claim_token: str,
        task_id: str | None,
        job_id: str | None,
        checked_at: datetime,
        force: bool,
    ) -> ClaimedSourceDocument:
        cursor.execute(
            """
            insert into document_deduplication_states (
                scope_key, document_id, status, created_at, updated_at
            ) values (%s, %s, 'pending', now(), now())
            on conflict (scope_key, document_id) do nothing
            """,
            (fingerprint.scope_key, fingerprint.document_id),
        )
        cursor.execute(
            """
            select completed_content_hash, pending_content_hash, status, decision,
                   claim_token, checked_at, lease_expires_at
              from document_deduplication_states
             where scope_key = %s and document_id = %s
             for update
            """,
            (fingerprint.scope_key, fingerprint.document_id),
        )
        row = cursor.fetchone()
        if row is None:
            raise RuntimeError(
                f"Deduplication state disappeared for [{fingerprint.scope_key}/{fingerprint.document_id}]."
            )

        completed_hash = _sha256_or_none(row[0])
        pending_hash = _sha256_or_none(row[1])
        status = str(row[2] or STATUS_PENDING)
        stored_decision = str(row[3] or "")
        stored_claim_token = str(row[4] or "")
        previous_checked_at = row[5]
        lease_expires_at = row[6]

        if status == STATUS_PROCESSING and stored_claim_token and stored_claim_token != claim_token:
            stale = (
                _utc_datetime(lease_expires_at) is not None
                and _utc_datetime(lease_expires_at) <= checked_at
            ) or (
                lease_expires_at is None
                and _utc_datetime(previous_checked_at) is not None
                and _utc_datetime(previous_checked_at) <= checked_at - CLAIM_STALE_AFTER
            )
            if not stale:
                raise DeduplicationClaimConflictError(
                    "Document processing is already claimed by another workflow run: "
                    f"{fingerprint.scope_key}/{fingerprint.document_id}"
                )

        if (
            status == STATUS_PROCESSING
            and stored_claim_token == claim_token
            and pending_hash == fingerprint.content_hash
            and stored_decision in {DECISION_NEW, DECISION_UPDATED}
        ):
            return _claimed(fingerprint, stored_decision, completed_hash)

        if not force and completed_hash == fingerprint.content_hash:
            decision = DECISION_DUPLICATE
            cursor.execute(
                """
                update document_deduplication_states
                   set status = 'completed', decision = 'duplicate', claim_token = null,
                       lease_expires_at = null,
                       pending_content_hash = null, pending_source_id = null,
                       task_id = %s, job_id = %s, checked_at = %s,
                       metadata = %s::json, updated_at = now()
                 where scope_key = %s and document_id = %s
                """,
                (
                    task_id,
                    job_id,
                    checked_at,
                    json.dumps(_fingerprint_metadata(fingerprint)),
                    fingerprint.scope_key,
                    fingerprint.document_id,
                ),
            )
            return _claimed(fingerprint, decision, completed_hash)

        decision = DECISION_NEW if completed_hash is None else DECISION_UPDATED

        cursor.execute(
            """
            update document_deduplication_states
               set pending_content_hash = %s, status = 'processing', decision = %s,
                   claim_token = %s, pending_source_id = %s, task_id = %s, job_id = %s,
                   checked_at = %s, lease_expires_at = %s, metadata = %s::json, updated_at = now()
             where scope_key = %s and document_id = %s
            """,
            (
                fingerprint.content_hash,
                decision,
                claim_token,
                fingerprint.source_id,
                task_id,
                job_id,
                checked_at,
                checked_at + CLAIM_STALE_AFTER,
                json.dumps(_fingerprint_metadata(fingerprint)),
                fingerprint.scope_key,
                fingerprint.document_id,
            ),
        )
        return _claimed(fingerprint, decision, completed_hash)

    def resume_result(self, plan: SourceDeduplicationPlan) -> dict[str, Any] | None:
        """Fence conversion/ingestion retries and return a prior committed result."""

        process_documents = plan.process_documents
        if not process_documents:
            return {}

        states: list[str] = []
        completed_results: list[dict[str, Any]] = []
        checked_at = datetime.now(UTC)
        with self.connection() as connection:
            with connection.transaction():
                with connection.cursor() as cursor:
                    for document in process_documents:
                        cursor.execute(
                            """
                            select completed_content_hash, pending_content_hash, status,
                                   claim_token, metadata
                              from document_deduplication_states
                             where scope_key = %s and document_id = %s
                             for update
                            """,
                            (document.scope_key, document.document_id),
                        )
                        row = cursor.fetchone()
                        if row is None:
                            raise DeduplicationClaimConflictError(
                                "Deduplication processing state no longer exists: "
                                f"{document.scope_key}/{document.document_id}"
                            )

                        completed_hash = _sha256_or_none(row[0])
                        pending_hash = _sha256_or_none(row[1])
                        status = str(row[2] or STATUS_PENDING)
                        claim_token = str(row[3] or "")
                        if (
                            status in {STATUS_PROCESSING, STATUS_FAILED}
                            and claim_token == plan.claim_token
                            and pending_hash == document.content_hash
                        ):
                            states.append(STATUS_PROCESSING)
                            cursor.execute(
                                """
                                update document_deduplication_states
                                   set status = 'processing', checked_at = %s,
                                       lease_expires_at = %s, updated_at = now()
                                 where scope_key = %s and document_id = %s
                                   and claim_token = %s and pending_content_hash = %s
                                """,
                                (
                                    checked_at,
                                    checked_at + CLAIM_STALE_AFTER,
                                    document.scope_key,
                                    document.document_id,
                                    plan.claim_token,
                                    document.content_hash,
                                ),
                            )
                            continue

                        if (
                            status == STATUS_COMPLETED
                            and completed_hash == document.content_hash
                            and pending_hash is None
                        ):
                            states.append(STATUS_COMPLETED)
                            metadata = _json_mapping(row[4])
                            ingest_result = metadata.get("ingest_result")
                            if isinstance(ingest_result, dict):
                                completed_results.append(dict(ingest_result))
                            continue

                        raise DeduplicationClaimConflictError(
                            "Deduplication processing claim is no longer owned by this workflow run: "
                            f"{document.scope_key}/{document.document_id}"
                        )

        if all(state == STATUS_PROCESSING for state in states):
            return None
        if all(state == STATUS_COMPLETED for state in states):
            return completed_results[0] if completed_results else {}
        raise DeduplicationClaimConflictError(
            "Deduplication plan contains a mixture of active and completed processing claims."
        )

    def mark_completed(
        self,
        plan: SourceDeduplicationPlan,
        ingest_result: Mapping[str, Any] | None = None,
    ) -> None:
        process_documents = plan.process_documents
        if not process_documents:
            return

        completion_metadata = json.dumps({"ingest_result": dict(ingest_result or {})})
        with self.connection() as connection:
            with connection.transaction():
                with connection.cursor() as cursor:
                    for document in process_documents:
                        cursor.execute(
                            """
                            update document_deduplication_states
                               set completed_content_hash = pending_content_hash,
                                   completed_source_id = pending_source_id,
                                   pending_content_hash = null, pending_source_id = null,
                                   status = 'completed', claim_token = null,
                                   lease_expires_at = null,
                                   completed_at = now(),
                                   metadata = (coalesce(metadata::jsonb, '{}'::jsonb) || %s::jsonb)::json,
                                   updated_at = now()
                             where scope_key = %s and document_id = %s
                               and claim_token = %s and pending_content_hash = %s
                            """,
                            (
                                completion_metadata,
                                document.scope_key,
                                document.document_id,
                                plan.claim_token,
                                document.content_hash,
                            ),
                        )
                        if cursor.rowcount != 1:
                            cursor.execute(
                                """
                                select completed_content_hash, status
                                  from document_deduplication_states
                                 where scope_key = %s and document_id = %s
                                """,
                                (document.scope_key, document.document_id),
                            )
                            completed_row = cursor.fetchone()
                            if not (
                                completed_row
                                and _sha256_or_none(completed_row[0]) == document.content_hash
                                and str(completed_row[1] or "") == STATUS_COMPLETED
                            ):
                                raise DeduplicationClaimConflictError(
                                    "Deduplication completion lost its processing claim: "
                                    f"{document.scope_key}/{document.document_id}"
                                )

    def mark_failed(self, plan: SourceDeduplicationPlan, error: str) -> None:
        with self.connection() as connection:
            with connection.transaction():
                with connection.cursor() as cursor:
                    for document in plan.process_documents:
                        metadata = _document_metadata(document)
                        metadata["last_error"] = error[:1000]
                        cursor.execute(
                            """
                            update document_deduplication_states
                               set status = 'failed', metadata = %s::json, updated_at = now()
                             where scope_key = %s and document_id = %s and claim_token = %s
                            """,
                            (
                                json.dumps(metadata),
                                document.scope_key,
                                document.document_id,
                                plan.claim_token,
                            ),
                        )


def sha256_file(
    path: Path,
    *,
    progress_callback: Callable[[Path, int], None] | None = None,
) -> str:
    digest = hashlib.sha256()
    bytes_read = 0
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
            bytes_read += len(chunk)
            if progress_callback is not None:
                progress_callback(path, bytes_read)
    return digest.hexdigest()


def _crawler_manifest_documents(
    *,
    raw_root: Path,
    scope_key: str,
    base_document_id: str,
    source_id: str,
    progress_callback: Callable[[Path, int], None] | None,
) -> list[SourceDocumentFingerprint]:
    manifest_path = raw_root / "completed_urls.json"
    if not manifest_path.is_file():
        return []
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Crawler completion manifest is unreadable: {manifest_path}") from exc

    records = _manifest_records(manifest)
    documents: list[SourceDocumentFingerprint] = []
    seen_document_ids: set[str] = set()
    for record in records:
        content_path_value = _first_string(record, "content_path", "markdown_path", "file_path", "path")
        if content_path_value is None:
            continue
        content_path = _safe_manifest_path(raw_root, content_path_value)
        if not content_path.is_file():
            raise RuntimeError(f"Crawler manifest content file was not found: {content_path}")

        source_url = _first_string(record, "source_url", "url", "original_url")
        canonical_url = _first_string(record, "canonical_url")
        page_id = _first_string(record, "page_id", "url_hash", "id")
        identity = _normalize_http_url(source_url) or page_id or content_path.relative_to(raw_root).as_posix()
        document_id = _stable_url_document_id(identity)
        if document_id in seen_document_ids:
            continue
        seen_document_ids.add(document_id)

        actual_hash = sha256_file(content_path, progress_callback=progress_callback)
        expected_hash = _record_content_hash(record)
        if expected_hash is not None and not hmac.compare_digest(actual_hash, expected_hash):
            raise RuntimeError(
                f"Crawler manifest hash mismatch for [{source_url or content_path.name}]."
            )
        documents.append(
            SourceDocumentFingerprint(
                scope_key=scope_key,
                document_id=document_id,
                content_hash=actual_hash,
                source_id=source_id,
                source_path=str(content_path),
                relative_path=content_path.relative_to(raw_root).as_posix(),
                source_url=_normalize_http_url(source_url),
                canonical_url=_normalize_http_url(canonical_url),
                page_id=page_id,
            )
        )
    return sorted(documents, key=lambda document: document.document_id)


def _manifest_records(value: Any) -> list[Mapping[str, Any]]:
    records: list[Mapping[str, Any]] = []
    if isinstance(value, list):
        for item in value:
            records.extend(_manifest_records(item))
        return records
    if not isinstance(value, dict):
        return records

    if any(key in value for key in ("content_path", "markdown_path", "file_path")):
        records.append(value)
        return records
    for key in ("completed_urls", "documents", "items", "pages", "results", "urls"):
        nested = value.get(key)
        if isinstance(nested, (dict, list)):
            records.extend(_manifest_records(nested))
    if not records:
        for nested in value.values():
            if isinstance(nested, (dict, list)):
                records.extend(_manifest_records(nested))
    return records


def _record_content_hash(record: Mapping[str, Any]) -> str | None:
    direct = _sha256_or_none(record.get("content_hash") or record.get("content_sha256"))
    if direct is not None:
        return direct
    hashes = _mapping(record.get("hashes"))
    return _sha256_or_none(hashes.get("content_sha256") or hashes.get("sha256"))


def _fallback_fingerprint(
    *,
    path: Path,
    raw_root: Path,
    scope_key: str,
    base_document_id: str,
    source_id: str,
    progress_callback: Callable[[Path, int], None] | None,
) -> SourceDocumentFingerprint:
    relative_path = path.relative_to(raw_root).as_posix()
    return SourceDocumentFingerprint(
        scope_key=scope_key,
        document_id=_stable_child_document_id(scope_key, base_document_id, relative_path),
        content_hash=sha256_file(path, progress_callback=progress_callback),
        source_id=source_id,
        source_path=str(path),
        relative_path=relative_path,
    )


def _stable_child_document_id(scope_key: str, base_document_id: str, identity: str) -> str:
    digest = hashlib.sha256(f"{scope_key}|{base_document_id}|{identity}".encode("utf-8")).hexdigest()[:40]
    return f"doc_{digest}"


def _stable_url_document_id(identity: str) -> str:
    source_identity = f"url:{identity}"
    digest = hashlib.sha256(source_identity.encode("utf-8")).hexdigest()[:40]
    return f"doc_{digest}"


def _safe_manifest_path(raw_root: Path, value: str) -> Path:
    candidate = Path(value.removeprefix("file://")).expanduser()
    if not candidate.is_absolute():
        candidate = raw_root / candidate
    resolved = candidate.resolve()
    try:
        resolved.relative_to(raw_root)
    except ValueError as exc:
        raise RuntimeError(f"Crawler manifest path escaped its raw directory: {value}") from exc
    return resolved


def _local_root(path: str) -> Path:
    if is_object_prefix(path):
        raise RuntimeError("s3:// source deduplication requires an object-storage adapter in this deployment.")
    root = Path(path.removeprefix("file://")).expanduser().resolve()
    if not root.is_dir():
        raise RuntimeError(f"Source directory was not found: {root}")
    return root


def _normalize_http_url(value: str | None) -> str | None:
    if value is None:
        return None
    raw = value.strip()
    try:
        parsed = urlsplit(raw)
    except ValueError:
        return raw or None
    if parsed.scheme.lower() not in {"http", "https"} or not parsed.netloc:
        return raw or None
    scheme = parsed.scheme.lower()
    netloc = parsed.netloc.lower()
    if scheme == "http" and netloc.endswith(":80"):
        netloc = netloc[:-3]
    if scheme == "https" and netloc.endswith(":443"):
        netloc = netloc[:-4]
    path = parsed.path or ""
    if path != "/":
        path = path.rstrip("/")
    else:
        path = ""
    return urlunsplit((scheme, netloc, path, parsed.query, ""))


def _claimed(
    fingerprint: SourceDocumentFingerprint,
    decision: str,
    previous_content_hash: str | None,
) -> ClaimedSourceDocument:
    return ClaimedSourceDocument(
        **asdict(fingerprint),
        decision=decision,
        previous_content_hash=previous_content_hash,
    )


def _fingerprint_metadata(fingerprint: SourceDocumentFingerprint) -> dict[str, Any]:
    return {
        "relative_path": fingerprint.relative_path,
        "source_url": fingerprint.source_url,
        "canonical_url": fingerprint.canonical_url,
        "page_id": fingerprint.page_id,
        "source_path": fingerprint.source_path,
    }


def _document_metadata(document: ClaimedSourceDocument) -> dict[str, Any]:
    return {
        "relative_path": document.relative_path,
        "source_url": document.source_url,
        "canonical_url": document.canonical_url,
        "page_id": document.page_id,
        "source_path": document.source_path,
    }


def _mapping(value: Any) -> Mapping[str, Any]:
    return value if isinstance(value, Mapping) else {}


def _required_string(value: Any, field: str) -> str:
    normalized = _optional_string(value)
    if normalized is None:
        raise RuntimeError(f"Required deduplication field is missing: {field}")
    return normalized


def _optional_string(value: Any) -> str | None:
    if isinstance(value, (str, int, float)) and str(value).strip():
        return str(value).strip()
    return None


def _first_string(payload: Mapping[str, Any], *keys: str) -> str | None:
    for key in keys:
        value = _optional_string(payload.get(key))
        if value is not None:
            return value
    return None


def _sha256_or_none(value: Any) -> str | None:
    normalized = _optional_string(value)
    if normalized is None or len(normalized) != 64:
        return None
    lowered = normalized.lower()
    if any(character not in "0123456789abcdef" for character in lowered):
        return None
    return lowered


def _utc_datetime(value: Any) -> datetime | None:
    if not isinstance(value, datetime):
        return None
    if value.tzinfo is None:
        return value.replace(tzinfo=UTC)
    return value.astimezone(UTC)


def _json_mapping(value: Any) -> dict[str, Any]:
    if isinstance(value, dict):
        return dict(value)
    if isinstance(value, str):
        try:
            decoded = json.loads(value)
        except json.JSONDecodeError:
            return {}
        return dict(decoded) if isinstance(decoded, dict) else {}
    return {}


__all__ = [
    "ClaimedSourceDocument",
    "DECISION_DUPLICATE",
    "DECISION_NEW",
    "DECISION_UPDATED",
    "DeduplicationClaimConflictError",
    "SourceDeduplicationPlan",
    "SourceDeduplicationStore",
    "SourceDocumentFingerprint",
    "discover_source_documents",
    "read_plan",
    "sha256_file",
    "write_plan",
]
