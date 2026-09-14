# Architecture Rules

Keep behavior inside one service when only that service owns it. Promote behavior
to a shared package only when it represents a stable cross-service contract or
reusable infrastructure primitive.

This follows the existing [package rules](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/packages/README.md),
[workspace layout](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/pyproject.toml), and
[CI boundary checks](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/.github/workflows/python-rag.yml).

## Put behavior at the right boundary

| Layer | Responsibility |
|---|---|
| Laravel route / Form Request / controller | HTTP policy, validation, principal resolution, transport |
| Laravel domain service | Authorization, dataset/task/document behavior, coordinated application state |
| Laravel repositories / clients | Application persistence and external calls |
| Python HTTP router | Validation, dependencies, response/error mapping |
| Python application/capability module | Service-owned use-case policy and ordering |
| Python domain | Pure models, errors, ports |
| Python adapter | Network, store, provider, artifact or callback I/O |
| Temporal workflow | Deterministic activity order, retry/timeouts, history-compatible commands |
| Temporal activity | Validated side effects, heartbeat, result and callback boundary |

Not every use case needs every layer. Avoid introducing an abstraction solely
to mirror this table.

## Preserve ownership

- Services do not import another service's implementation.
- Shared packages never import service code.
- Laravel owns application PostgreSQL records and public authorization.
  Python returns typed results or signed events.
- Workers call their application code directly; the indexer has no ingestion
  FastAPI app and does not call the bridge to index.
- Keep storage scope server-derived. User metadata cannot select a different
  collection, namespace, or model.
- Keep retry classification beside the client whose errors it understands.
- Pure transformations stay independent of network/filesystem/environment I/O.
- Required dependencies should fail clearly at startup/import when absent,
  rather than silently changing behavior.

These are architectural constraints, not a claim of per-container secret
isolation: current Compose uses shared dotenv injection.

## Change contracts deliberately

For HTTP, update request validation, the consumer, contract tests, and relevant
[API reference](../Reference/rest_apis.md). The
[MCP schema mismatch](../Reference/mcp_query_search_contract.md#actual-mcp-structured-content)
shows why declared schemas and emitted envelopes must be checked together.

For ingestion, preserve stable document identity and dataset embedding
compatibility. Test partial failure at the actual
[commit boundaries](../Operations/ingestion_recovery.md), not just a happy path.

For Temporal, preserve deterministic command history and stable names. Review
[queue migration and replay](../Operations/temporal_operations.md#queue-migrations-and-replay)
before changing queue selection, activity order, or terminal callbacks.

For a new provider or store, begin in the owning service, implement its narrow
port, and move only reusable infrastructure into the corresponding package.
Register model choices/allowlists and document data migration implications.

## Keep documentation verifiable

The [Repository Map](../Reference/8_repo_map.md) links to implementation paths on
GitHub. Verify those targets against the checkout when updating the map; the
site build does not validate external repository links. Use relative Markdown
links between handbook pages so the existing site tooling can resolve them.

Update the authoritative topic page and link to it from READMEs.
Keep examples aligned with active request validation and configuration, including
fallback behavior. Do not treat a comment, skill file, or historical changelog
as stronger evidence than running implementation.
