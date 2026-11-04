# Direct text ingestion

The maintained API contract, dataset/token setup, executable smoke test,
asynchronous status, retries, and source deletion are documented in
[Direct Text Ingestion](../_documentation/Reference/direct_text_ingestion.md).

This pointer preserves the earlier guide's location. A 202 response means
asynchronous acceptance; readiness must be checked separately.

## Python ownership option (Epoch 9)

The default backend remains Laravel. With `TEXT_INGESTION_BACKEND=python`, the
public routes and Sanctum `rag:text-ingest` contract remain compatible, while
Python owns POST, signed completion and direct-text DELETE using internal
`rag:ingest` plus exact Python dataset INGEST grants. There is no fallback or
dual execution. See the [coordinated cutover and rollback guide](../_documentation/Operations/python_text_ingestion.md)
for required real grant backfill, drift freeze, legacy inventory/drain gates and
interrupted deletion recovery. Managed documents, URL and crawler ingestion
remain Laravel-owned.
