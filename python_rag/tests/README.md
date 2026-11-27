# Python RAG tests

Use the [testing guide](../../_documentation/Developer/testing.md) to run the
workspace tests. Configure isolated PostgreSQL, model, and store endpoints
for opt-in integration cases.

Live acceptance uses the service API and disposable datasets. URL tests also
require a configured crawler and scraper worker. See [deployment](../../_documentation/Operations/python_runtime.md) and [external crawler setup](../../_documentation/Operations/crawler_v2_local_upgrade.md).

## Service API text E2E

The opt-in direct-text test uses `RAWKI_INTEGRATION_BACKEND_URL` (origin, default
`http://127.0.0.1:8080`), `RAWKI_INTEGRATION_SERVICE_TOKEN` (registered service JWT
with `rag:ingest`), `RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID` (a disposable active
dataset with that service's ingest grant), and `RAWKI_INTEGRATION_QDRANT_COLLECTION`
(the exact collection for that dataset). Configure the Qdrant endpoint/API key as
needed. Run before the short-lived JWT expires. It checks idempotent admission,
ready workflow/source identity, exact indexed content and source-scoped cleanup.
It never retires the operator-supplied dataset or prints the JWT.

Retrieval characterization, adapter and live scoping tests live under
`packages/retrieval/tests`; worker Docker packaging checks live under
`tests/deployment`. Bridge-only HTTP tests and retired activation models are not
part of the current suite. Frozen parity/security fixtures remain intact.
