# Troubleshooting

Identify the failing boundary before restarting or retrying. Preserve task,
source, workflow, and request identifiers, then use
[Monitoring & Observability](./monitoring.md) to compare execution with storage
and projected state.

| Symptom | Check first | Next action |
|---|---|---|
| App key or encryption error | `APP_KEY` has a real `base64:` value | Follow [Installation](../Getting%20Started/4_installation_zero_to_up.md); recreate Laravel after changing dotenv |
| App unreachable on 8080 | Startup mode and selected Compose layers | Local/UI modes publish loopback; server mode needs the intended reverse proxy |
| Query returns 503 before retrieval | Number of active local users; dataset collection | Create/select an eligible principal, or finish ingestion; distinguish authorization failure from `dataset_not_ready` |
| Explicit bearer query fails | Active user, token `query` ability, dataset access | Invalid explicit tokens do not use the implicit-user fallback |
| Text ingestion returns 401/403 | Real token with literal `rag:text-ingest` ability and explicit dataset ingest grant | Follow [Direct Text Ingestion](../Reference/direct_text_ingestion.md); a query grant is insufficient |
| Text ingestion returns 409 | Reused key with changed body; same source already active | Replay the identical request or wait for the active source operation |
| Accepted task does not advance | Temporal history, queue names, live pollers | Check the assigned worker and activity; configuration listings are insufficient |
| Callback rejected / UI stays running | Shared secret, exact URL, clock skew, response status | Use [signed callback diagnostics](./temporal_operations.md#signed-worker-callbacks) |
| Crawler/converter unreachable | External container, shared network, configured base URL/path/token | Start the [external tools](../Getting%20Started/2_setup.md#external-tools) and align both Laravel and worker configuration |
| Empty or invalid Markdown artifacts | Converter result, explicit artifact references, shared mount | Correct the conversion/handoff before retrying |
| Embedding or dimension failure | Dataset's stored provider/model, model availability, collection dimension | Repair that runtime or rebuild deliberately; do not silently change dataset embedding space |
| Vectors exist but graph is absent | Graph option, passthrough, extraction failures, graph namespace | Use [Ingestion Recovery](./ingestion_recovery.md); unchanged re-ingestion may skip graph work |
| Query has hits but no answer | Request `generate`, process `RAG_GENERATE_ANSWER`, selected context | Review [generation conditions](../Core%20Concepts/query_retrieval.md#8-build-context-fetch-facts-and-optionally-generate) |
| Reranker unavailable | Bridge endpoint, local model download/inference, response shape | Retrieval normally falls back to original ordering; compare retrieval evidence |
| Environment edit has no effect | Container creation time, cached Laravel config, persisted Settings/workflow input | Recreate consumers using the matching lifecycle mode |
| Source edit has no effect in Python | Python image build | Local mode mounts Laravel only; rebuild the affected Python role |
| MCP client rejects structured output | Advertised schema versus runtime envelope | See the [known MCP mismatch](../Reference/mcp_query_search_contract.md#actual-mcp-structured-content) |

## Known implementation gaps

These are current limitations, not configuration recipes:

- MCP's advertised output schema differs from the runtime envelope.
- The bridge's schedule mapping uses fixed cron expressions; Laravel's
  configurable cron values are not forwarded into that mapping.
- Schedule replacement is delete-then-create, and deletion exceptions can be
  suppressed, so a successful control response alone does not prove the desired
  schedule exists.
- Worker listings and some health results are configuration evidence rather
  than live worker readiness.
- Qdrant replacement, graph commit, and Laravel callbacks are separate
  boundaries. Ordinary partial embeddings can be committed; ordinary
  unchanged-content retries may not repair missing chunks or graph facts.
- Existing Qdrant collections receive no dimension/distance compatibility
  preflight before replacement. A failed incompatible upsert can follow deletion.
- Graph-only execution exists internally, but there is no supported public
  graph-only repair endpoint or CLI.
- The management API lacks blanket application authentication in the supplied
  single-user deployment. Query, direct-text, MCP, and callback boundaries have
  separate policies; see [Authorization](../Core%20Concepts/authorization_dataset_scope.md).
- Log redaction is not comprehensive across every exception path.

Recovery details and migration cautions belong in
[Ingestion Recovery](./ingestion_recovery.md) and
[Temporal Operations](./temporal_operations.md#queue-migrations-and-replay).
