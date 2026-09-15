# Troubleshooting

Identify the failing boundary before restarting or retrying. Preserve task,
source, workflow, and request identifiers, then use
[Monitoring & Observability](./monitoring.md) to compare execution with storage
and projected state.

## Startup & containers

| Symptom | Check first | Next action |
|---|---|---|
| App key or encryption error | `APP_KEY` has a real `base64:` value | Follow [Installation](../Getting%20Started/4_installation_zero_to_up.md); recreate Laravel after changing dotenv |
| App unreachable on 8080 | Startup mode and selected Compose layers | Local/UI modes publish loopback; server mode needs the intended reverse proxy |
| Environment edit has no effect | Container creation time, cached Laravel config, persisted Settings/workflow input | Recreate consumers using the matching lifecycle mode |
| Source edit has no effect in Python | Python image build | Local mode mounts Laravel only; rebuild the affected Python role |

## Authentication & authorization

| Symptom | Check first | Next action |
|---|---|---|
| Explicit bearer query fails | Active user, token `query` ability, dataset access | Invalid explicit tokens do not use the implicit-user fallback |
| Text ingestion returns 401/403 | Real token with literal `rag:text-ingest` ability and explicit dataset ingest grant | Follow [Direct Text Ingestion](../Reference/direct_text_ingestion.md); a query grant is insufficient |

## Query & retrieval

| Symptom | Check first | Next action |
|---|---|---|
| Query returns 503 before retrieval | Number of active local users; dataset collection | Create/select an eligible principal, or finish ingestion; distinguish authorization failure from `dataset_not_ready` |
| Query has hits but no answer | Request `generate`, process `RAG_GENERATE_ANSWER`, selected context | Review [generation conditions](../Core%20Concepts/query_retrieval.md#8-build-context-fetch-facts-and-optionally-generate) |
| MCP client rejects structured output | Advertised schema versus runtime envelope | See the [known MCP mismatch](../Reference/mcp_query_search_contract.md#actual-mcp-structured-content) |

## Ingestion

| Symptom | Check first | Next action |
|---|---|---|
| Text ingestion returns 409 | Reused key with changed body; same source already active | Replay the identical request or wait for the active source operation |
| Crawler/converter unreachable | External container, shared network, configured base URL/path/token | Start the [external tools](../Getting%20Started/2_setup.md#external-tools) and align both Laravel and worker configuration |
| Empty or invalid Markdown artifacts | Converter result, explicit artifact references, shared mount | Correct the conversion/handoff before retrying |
| Vectors exist but graph is absent | Graph option, passthrough, extraction failures, graph namespace | Use [Ingestion Recovery](./ingestion_recovery.md); unchanged re-ingestion may skip graph work |

## Temporal & callbacks

| Symptom | Check first | Next action |
|---|---|---|
| Accepted task does not advance | Temporal history, queue names, live pollers | Check the assigned worker and activity; configuration listings are insufficient |
| Callback rejected / UI stays running | Shared secret, exact URL, clock skew, response status | Use [signed callback diagnostics](./temporal_operations.md#signed-worker-callbacks) |

## Models & embeddings

| Symptom | Check first | Next action |
|---|---|---|
| Embedding or dimension failure | Dataset's stored provider/model, model availability, collection dimension | Repair that runtime or rebuild deliberately; do not silently change dataset embedding space |
| Reranker unavailable | Bridge endpoint, local model download/inference, response shape | Retrieval normally falls back to original ordering; compare retrieval evidence |

## Known implementation gaps

Use the authoritative explanation for each limitation:

| Limitation | Details and operational consequence |
|---|---|
| MCP schema differs from its runtime envelope | [MCP contract](../Reference/mcp_query_search_contract.md#actual-mcp-structured-content) |
| Cron controls are hard-coded; schedule replacement is not atomic | [Temporal schedules](./temporal_operations.md#schedules) |
| Worker listings and some health results report configuration only | [Monitoring evidence](./monitoring.md#choose-the-evidence) |
| Vector writes, graph writes, and callbacks are separate boundaries | [Recovery](./ingestion_recovery.md#diagnose-by-completed-boundary) |
| Existing collections lack dimension/distance preflight; ordinary partial embeddings may persist | [Chunking & Embeddings](../Core%20Concepts/Ingestion/chunking_embeddings.md#embedding-compatibility-is-a-dataset-invariant) |
| Graph-only repair is internal and can leave stale facts | [Graph repair](../Core%20Concepts/Ingestion/graph_enrichment.md#graph-repair-and-preview) |
| Management routes lack blanket application authentication | [Access boundaries](../Core%20Concepts/authorization_dataset_scope.md#access-boundaries) |
| Laravel currently enables graph query scope unconditionally | [Scope-factory limitation](../Core%20Concepts/authorization_dataset_scope.md#trusted-query-fields) |
| Log redaction is not comprehensive across all exception paths | [Logging boundaries](./monitoring.md#logging-boundaries) |
