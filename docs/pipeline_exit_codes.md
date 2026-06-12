# Pipeline Exit Codes

Automation should use process exit codes instead of parsing logs.

| Code | Meaning |
|------|---------|
| `0` | Success |
| `1` | Runtime failure |
| `2` | Validation, bad input, or bad configuration |
| `3` | Partial success |

## Command Behavior

| Command | `0` | `1` | `2` | `3` |
|---------|-----|-----|-----|-----|
| `python python_rag/application/cli/commands/retry_ingest_docs.py` | All requested documents found and re-ingested or dry-run planned | One or more re-ingest batches failed | Invalid CLI arguments, missing root, or no doc IDs provided | No candidate page directories or one or more requested doc IDs not found |
| `python python_rag/application/cli/commands/prune_missing_docs.py` | No stale documents found, or dry-run completed with no delete failures | One or more stale document deletes failed, or Qdrant/runtime failure | Missing root or invalid CLI arguments | Stale documents were found and deleted, or dry-run planned deletions |

## Fallback Behavior

| Condition | Stage | Behavior | Reported Status |
|-----------|-------|----------|-----------------|
| Invalid ingest document | Python API ingest | Skip the invalid document, record validation errors in the ingest summary, and continue with valid documents. | Request succeeds when at least one valid document remains; request fails with HTTP `400` when no valid content remains. |
| Failed embedding chunk or document | Python API ingest | Log the failed chunk as skipped, remove fully failed documents from processed counts, and continue indexing valid points. | Request succeeds when at least one point remains; request fails with HTTP `500` when every prepared chunk fails embedding. |
| Failed Temporal activity | Temporal activity worker | Mark app job/source metadata with the error. Temporal keeps workflow state and retries activities according to workflow/activity policy. | State is stored in `pipeline_jobs`, `ingestion_sources`, and Temporal PostgreSQL history. |
| Retry exhausted | Temporal workflow/activity | Temporal records the failed workflow/activity and Laravel recovery can start a new workflow run for eligible source ingestion jobs. | Failed metadata includes workflow ID, run ID, retry count, error type, and app job/source identifiers. |
| Requested retry doc ID not found | Retry ingest CLI | Continue matching and re-ingesting any found IDs, then report the unmatched IDs. | Exit `3` when at least one requested ID was not found and no batch failed. |
| Failed stale-document delete | Prune ingest CLI | Continue attempting remaining deletes and count failures. | Exit `1` when any delete request fails. |

Health-check targets use `1` for runtime/service failures.
