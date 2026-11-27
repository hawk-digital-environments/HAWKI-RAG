# v%%VERSION%%

### What's New

[//]: # (- The main new features and changes in this version.)

- **Headless service API.** Clients call FastAPI over REST or MCP on the private Docker
  network using service JWTs.
- Inline text and file uploads share the managed document API and workflow.
- The Temporal scraper worker handles crawler requests. The production
  scraper image remains an optional Compose addon.

- **Verified managed-document deletion.** The earlier deletion path used a
  dedicated Temporal workflow. The Python backend now owns scoped cleanup of
  Qdrant points and Neo4j contributions. It uses Temporal to cancel active
  ingestion when needed and keeps deletion pending until owned writers have
  drained and sink cleanup is verified. Failed or uncertain operations retain
  the evidence needed for retries.
- **Immediate dataset removal with background cleanup.** Dataset DELETE hides and
  archives the dataset immediately, blocking ingestion, retries and queries. The
  cleanup coordinator can resume scoped cleanup after writer drain. The deployment
  keeps background cleanup fenced pending verified historical ownership. Verified
  completion retains the dataset tombstone and preserves shared graph provenance.

### Quality of Life

[//]: # (- Improvements and enhancements that improve the user experience.)

- **Automatic cleanup after cancellation.** Current managed writers acknowledge
  completion only after in-flight sink calls finish, then resume pending deletion
  automatically. The task API preserves evidence until scoped cleanup is verified.
- **Clearer ingestion progress.** The pipeline API reports chunk and vector counts from completed worker events.

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
  the existing service-scoped upload model. Dataset grants and signed worker
  callbacks remain part of the authorization boundary.

### Internals

[//]: # (- Changes that are mostly relevant to maintainers and contributors, such as refactors, dependency updates, CI changes, etc.)

- **Simpler deletion implementation.** Reuse the existing signed Temporal client,
  remove redundant request classes and unused cleanup code, and reduce duplicate
  state queries, document reloads and historical workflow checks.
- **Deletion safety regressions and operations guidance.** Cover transaction races,
  signed worker contracts, API deletion, shared graph preservation and live
  writes completing after cancellation. See the
  [deletion operations guide](../_documentation/Operations/temporal_operations.md#dataset-deletion-in-the-background)
  for scheduler deployment and recovery behavior.

- Deployment-owned model settings and dataset metadata select providers and
  physical storage scope. Live model readiness currently probes Ollama.

### Deprecation

[//]: # (- List of features or functionalities that have been deprecated in this version.)
