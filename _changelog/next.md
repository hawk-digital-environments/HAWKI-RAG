# v%%VERSION%%

### What's New

[//]: # (- The main new features and changes in this version.)

- **Verified managed-document deletion.** Remove a document's Qdrant points and
  Neo4j contributions through durable Temporal orchestration. Deletion remains
  pending until owned writers have drained and scoped sink cleanup is verified;
  failed or uncertain operations retain the evidence needed for retries.
- **Immediate dataset removal with background cleanup.** Dataset DELETE hides and
  archives the dataset immediately, blocking ingestion, retries and queries. The
  scheduler resumes scoped cleanup automatically after writer drain, failures or
  restarts. A later upload may reactivate the dataset only after cleanup reaches
  verified `deleted`; other datasets and shared graph contributions remain intact.

### Quality of Life

[//]: # (- Improvements and enhancements that improve the user experience.)

- **Automatic cleanup after cancellation.** Current managed writers acknowledge
  completion only after in-flight sink calls finish, then resume pending deletion
  automatically. The pipeline controller waits for verified cleanup before hiding
  task history; ambiguous outcomes and legacy writers still require reconciliation.
- **Clearer ingestion progress.** Display chunk and vector counts from completed
  worker events in the pipeline controller.

### Bugfix

[//]: # (- Bug fixes included in this version.)

- **Deleted content stays deleted.** Fence pending and undispatched managed sources,
  reject late writer retries, and prevent stale callbacks or replacement
  continuations from restoring deleted state. Cancellation alone no longer counts
  as proof that sink writes have stopped.
- **Exact historical cleanup scope.** Use recorded document/source/dataset ownership
  and physical collection/namespace evidence, including effective graph indexing
  enabled by converter passthrough. Preserve shared Neo4j provenance and reject
  ambiguous historical scope instead of guessing from current configuration.
- **Recoverable uploads and replacements.** Reject unsupported replacement files
  before destructive cleanup, commit ownership and dispatch input before Temporal
  startup, and retain workflow identities across lost acknowledgements and retries.
- **Consistent management access.** Align document update, delete and cancel with
  the existing single-user management upload model. Direct-text token and dataset
  grant checks, query authorization and internal worker HMAC remain unchanged.
- **Duplicate Pipeline Controller uploads.** Reject an identical file while an
  undeleted managed copy exists in the same dataset, before Temporal or either
  sink receives new work. The file can be uploaded again after verified deletion.

### Internals

[//]: # (- Changes that are mostly relevant to maintainers and contributors, such as refactors, dependency updates, CI changes, etc.)

- **Simpler deletion implementation.** Reuse the existing signed Temporal client,
  remove redundant request classes and unused cleanup code, and reduce duplicate
  state queries, document reloads and historical workflow checks.
- **Deletion safety regressions and operations guidance.** Cover transaction races,
  signed cross-language contracts, UI deletion, shared graph preservation and live
  writes completing after cancellation. See the
  [deletion operations guide](../_documentation/Operations/temporal_operations.md#dataset-deletion-in-the-background)
  for scheduler deployment and recovery behavior.
- **Future deletion architecture documented.** Record the target design for
  dataset deletion — a Postgres generation registry as the single
  accessibility switch, generation-scoped Qdrant/Neo4j resources, and
  asynchronous teardown — plus its limits around partial deletion and
  property-based graph generations. See the
  [deletion workflow guide](../_documentation/Operations/deletion_workflow.md#future-deletion-architecture).

- The bridge `GET /config` endpoint was removed, and the Python services no longer
read model-selection environment variables (`RAG_DEFAULT_PROVIDER`,
`OLLAMA_*_MODEL`, `LITELLM_*_MODEL`, `GRAPH_OLLAMA_*` on the Python side).
Provider and model names now travel exclusively with each Laravel request:

    - ingestion payloads must include `ingestion.graph_model` and `ingestion.vision_model`
    - `/query` requests must include `provider`, `chat_model`, and `vision_model`

Any client that called the bridge `/query` endpoint directly (outside Laravel)
must send these fields explicitly; missing fields now fail validation instead of
falling back to environment defaults. The provider startup probe was removed
along with `RAG_DEFAULT_PROVIDER`; startup checks cover Qdrant and Neo4j only.
Endpoint/auth variables (`OLLAMA_API_URL`, `LITELLM_API_URL/KEY`, Qdrant/Neo4j
addresses) remain unchanged.

### Deprecation

[//]: # (- List of features or functionalities that have been deprecated in this version.)
