# Python RAG deployment

HAWKI-RAG serves the authenticated `/v1/*` API on the private Docker network.
Only `/v1/health` and the published schema are available without a service JWT.
The API verifies issuer, audience, key, scopes and tenant before applying
exact dataset grants. Workers sign internal callbacks.

## Configuration and schema

Use `.env.example` for repository builds and `_docker_production/.env.example` for
registry releases. The current backend requires Alembic revision **0009** and a
separate runtime login with only the grants verified by readiness. Keep the
migration DSN outside the application environment. For inspected local setup,
`make migrate-core` uses an explicit operator-supplied migration DSN.

Keep deployment values in the untracked project `.env` and provider fragments.
The tracked files under `docker/env/` and `_docker_production/env/` apply service
defaults, private routes, and safeguards. Production uses these files to pass
each service only its required settings. Compose resolves substitutions in the
service files against its project environment. Load addon fragments after the
production `.env` when using `docker compose` directly.

When upgrading, rename `HAWKI_BACKEND_*` to `RAG_BACKEND_*`, `HAWKI_RAG_*` to
`RAG_*`, and other `HAWKI_*` settings to `RAG_*` in your project files,
deployment variables, and service overrides. Preserve the values, including
the JWT audience. Regenerate the local development backend settings with
`scripts/provision_local_api.py` and use images from the matching release.

The deployment requires a service issuer and its public JWKS key ID, model and
store configuration, signed callback credentials, and the shared artifact
permissions. Preserve the Compose project name and existing volumes on upgrades.

## Production crawler

To run the optional website scraper, copy `_docker_production/.env.scraper.example`
to `_docker_production/.env.scraper` and add `docker-compose.scraper.yml` after the
base file in `COMPOSE_FILE`. The addon defaults to
`digitalenvironments/hawki-rag-scraper:latest`. Set `RAG_SCRAPER_IMAGE` to a tag or
digest your deployment can pull. The backend and Temporal scraper worker reach
the container at `http://scraper` on a dedicated Docker network.

Verify the image implements `permanent-job-v2` before setting
`RAG_BACKEND_URL_INGESTION_ENABLED=true` in the base `.env`. For an external
crawler, use the base Compose file and set `EXTERNAL_SCRAPER_URL` in `.env`.
Either crawler needs access to the shared artifact storage.

## Local development

The base Compose builds the Python services. The local overlay supplies a
development HTTPS issuer, a pinned crawler, and dedicated Temporal queues.
Prepare the crawler per [its guide](crawler_v2_local_upgrade.md), then run
`python_backend/.venv/bin/python scripts/provision_local_api.py` with a local
Docker context and a configured root environment. The script creates ignored
service issuer material and a backend-only settings file in `.secrets/local-api/`.
Apply the schema and runtime grants before starting the local stack. The
`scripts/bootstrap_local_database.py` helper is restricted to the local
PostgreSQL container and counts historical tables before and after bootstrap.
If the local runtime role already exists, provision its existing password as
`.secrets/local-api/runtime-db-password` with restrictive permissions before
running the provisioning script. The former gateway credential directory may
also contain database or issuer material. Check its contents before retiring it.

```sh
make up-core-local
python_backend/.venv/bin/python -m pytest python_backend/tests/test_deployment_configuration.py
```

Both `make up-core` and `make up-core-local` serve REST and
[MCP](../Reference/direct_text_ingestion.md#submit-through-local-mcp) on the
private Docker network. Clients on that network use `http://rag_backend/v1` for
REST and `http://rag_backend/mcp` for MCP.

The graph and URL smoke tools and ingestion benchmarks accept an explicit
`RAWKI_API_BASE_URL` ending in `/v1` and a short-lived service JWT via
`RAWKI_API_TOKEN_FILE`. Run them from a trusted client that can reach the
backend. Use a service with the required query, ingestion and management scopes.
These tools create disposable datasets.

## Production

Use `_docker_production/docker-compose.yml` and the relevant addons. The stack
keeps FastAPI on the private Docker network and leaves its host port unpublished.
If upgrading from the former backend-only overlay, transfer the required backend
settings to the configured environment before startup.

The current branch needs matching published Python images, operator-provisioned
roles and credentials, and an approved schema rollout. Live model readiness
currently checks Ollama inventories. A LiteLLM deployment requires a reviewed
provider-specific probe.

## Historical evidence and reservations

The 27 original `public.*` tables remain frozen evidence, documented in
[public schema history](../History/public_schema.md). Fresh Python deployments
do not execute their former source-level migrations. The removed migrations
remain available in Git at `5391cb23:database/migrations/`.

Preserve the shared volume, `/shared/.historical-orphans`,
`/shared/.crawler-runtime/legacy-archive`, reserved original job/source identities,
and every referenced file. Back them up together with historical SQL evidence.
Do not delete, relocate, adopt, seal or manufacture completion for these records.

The `gebuhren` archive records custody **UNKNOWN**, deletion **UNVERIFIED**, six
unchanged preserved files and hashes of the original 16 SQL records. It is
isolated from new admission/dispatch and is not active runtime ownership. Missing
or corrupted configured archive evidence makes new admission fail closed.
Historical crawler imports retain their own unsealed state and identity/root
reservations.

## Readiness and reconciliation

Run `python -m hawki_backend.readiness --live` in the backend. Use
`python_backend/scripts/check_readiness.py --live` for read-only cross-service
configuration checks. Existing signed historical deletion starts and receipts
always refuse dispatch or acknowledgement. Managed, text and URL cleanup still
verify durable ownership, writer quiescence and exact sink scope.

Previously recorded graph-clear operations can be reconciled on the private
backend connection using its authenticated receipt endpoint and verified sink
evidence. Graph clearing uses the Python-owned operation. Background dataset
cleanup remains
disabled in both Compose models pending verified historical handoff.

Qdrant collections are created lazily on first indexing. Live readiness still
probes every configured Qdrant client, but an active dataset with valid canonical
collection metadata and no tenant-matched managed-document, direct-text, URL
request, or URL source history may lack its collection. Any ingestion history,
including pending, failed, cancelled, or deleted records, requires the canonical
collection to exist. Unavailable database evidence or invalid metadata fails
readiness; this exception does not recreate or repair missing collections.

New managed file uploads carry `managed_upload_protocol=2` and start directly
with conversion, followed by indexing and ready projection. The converter
prepares the local raw artifact through the shared root-confined upload stager;
no scraper activity or external crawler is invoked. The pipeline API projects
scraping as skipped and has no scraper events for these uploads. URL ingestion
keeps its crawler stage. Inline text is staged as Markdown and runs through the
same conversion and indexing workflow as file uploads. Upload histories
without the version field retain their recorded scraper staging sequence for
Temporal replay. Deploy converter/workflow workers before enabling new backend
uploads; existing history and upload metadata are not rewritten.
