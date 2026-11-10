# Epoch 11 URL dispatch recovery validation

Validated locally on 2026-11-10. No production deployment, staging, commit, or push.

## Recovery contract

URL starts use REJECT_DUPLICATE and USE_EXISTING, with source, generation,
URL operation, control operation, workflow family and type verified before
adoption. The protocol marker commits with the dispatch claim. A definitive
absence permits resubmitting that same operation; unavailable or ambiguous
lookups do not. A foreign ownership rejection persists beyond history expiry.

Protocol-1 workflows keep their terminal outcome in an open execution until
PostgreSQL contains the exact run receipt and terminal evidence and failure has
been finalized or successful publication verified. Only then does the backend
acknowledge the workflow. Activity deadlines remain; workflow execution/run
deadlines are omitted. Missing acknowledgements remain scheduler-recoverable
after pending ownership clears. No new table or distributed lock is used.

Deploy updated workflow workers and remove old workers from their queue before
enabling backend protocol-1 starts. Already-erased pre-upgrade history cannot be
reconstructed: old unconfirmed operations without the marker fail closed on
NOT_FOUND. Backend-managed delete/recrawl persists fencing before cancellation.

## Results

- Full backend, excluding integration marker: 1475 passed, 123 skipped,
  5 deselected.
- Focused URL, backend workflow control and adapter suite with disposable
  PostgreSQL and local Temporal enabled: 356 passed. This includes 31 PostgreSQL
  cases and 22 combined PostgreSQL/Temporal cases.
- Worker, contracts, adapter and selected bridge regression suite: 144 passed.
- Ruff: passed. Formatting check of affected packages: 83 files formatted.
- git diff --check: passed.

Counts overlap and must not be summed. PostgreSQL 16 and Temporal 1.25.2 were
used with Temporal Python SDK 1.30.0. Long-outage tests backdate database rows
90 days and verify open executions with no execution/run deadline; they do not
represent 90 days of elapsed live execution. Missing history after a persisted
terminal handoff is also covered by adapter/backend tests. A manual closed-history
deletion probe did not establish immediate deletion and is not counted as proof.

Crash windows A–E, concurrent scheduler/request recovery, lost acceptance and
receipts, completed-run protection, foreign ownership, retry/publication,
terminal handoff crashes, delete and successor races are covered. Identity is
preserved throughout recovery.

## Fix scope

Production implementation:

- python_backend/src/hawki_backend/workflow_control/{repository,service}.py
- python_backend/src/hawki_backend/url_ingestion/{repository,service,scheduler}.py
- python_rag/packages/workflow_control/src/hawki_workflow_control/temporal_client.py
- python_rag/packages/contracts/src/hawki_rag_contracts/pipeline/temporal.py
- python_rag/services/hawki_workflow_worker/src/hawki_workflow_worker/workflows/ingest_source.py

Tests:

- python_backend/tests/url_ingestion/{conftest,test_lifecycle,test_postgres}.py
- python_backend/tests/url_ingestion/test_dispatch_recovery.py
- python_backend/tests/url_ingestion/test_temporal_dispatch_recovery.py
- python_backend/tests/url_ingestion/test_terminal_acknowledgement.py
- python_backend/tests/url_ingestion/test_temporal_terminal_acknowledgement.py
- python_backend/tests/url_ingestion/terminal_workflow_fixture.py
- python_rag/packages/workflow_control/tests/{test_url_dispatch,test_workflow_status}.py
- python_rag/services/hawki_workflow_worker/tests/unit/test_url_terminal_acknowledgement.py

Documentation: this report, python_url_ingestion.md and workflow worker README.md.
The workspace contains other pre-existing changes outside this fix scope.
