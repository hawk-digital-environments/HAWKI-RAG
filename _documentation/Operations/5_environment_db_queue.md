---
title: Environment & Configuration
---

# Environment & Configuration

<div className="hero">

Before changing a value, ask whether services must be recreated, data must be
re-ingested, or persistent state is affected.

[Configuring a new installation? Start here](../Getting%20Started/4_installation_zero_to_up.md)
· [Ready-made provider setups? Recipes](../Reference/9_model_provider_recipes.md)

</div>

:::info How to use this reference

This is the complete configuration reference for the production stack: how
the environment is assembled, what every variable does, its allowed values
and default, and when to set it. First-install secrets belong in
[Installation](../Getting%20Started/4_installation_zero_to_up.md);
queues, timeouts, schedules, and the callback protocol belong in
[Temporal Operations](./temporal_operations.md);
worked provider setups (Ollama, local LiteLLM, external LiteLLM) live in the
[Model Provider Recipes](../Reference/9_model_provider_recipes.md).

:::

## Find the setting you need

<div className="grid-cards">

- <span className="grid-icon">🧩</span> __Stack composition__
  Which containers run: `COMPOSE_FILE` and the addon files.
  [Open section](#stack-composition-compose_file)

- <span className="grid-icon">⚙️</span> __Model runtime__
  Provider switch, gateway, models, reranking, warmup.
  [Open section](#model-runtime-provider-gateway-models)

- <span className="grid-icon">🗄️</span> __Laravel state__
  PostgreSQL, database queues, cache, and sessions.
  [Open section](#postgresql-and-laravel-state)

- <span className="grid-icon">🔌</span> __Ingestion tools__
  Crawler, converter, endpoints, and authentication.
  [Open section](#scraper-and-file-converter)

- <span className="grid-icon">⏱️</span> __Temporal__
  Workflow routing, schedules, retries, and time limits.
  [Open guide](./temporal_operations.md)

</div>

## Stack composition (COMPOSE_FILE)

`COMPOSE_FILE` — set at the top of `.env` — is the colon-separated list of
compose files Docker Compose loads. It decides **which containers run**:

| File | Adds | Pairs with |
|---|---|---|
| `docker-compose.yml` | Base stack: Laravel app, migrator, PostgreSQL, Temporal, Qdrant, Neo4j, file-converter, bridge, rerank service, four workers | always first |
| `docker-compose.with-ollama.yml` | Local Ollama model server (entrypoint pulls `HAWKI_RAG_OLLAMA_MODELS`) | `.env.ollama` fragment |
| `docker-compose.with-ollama-and-gpu.yml` | GPU device reservation for Ollama | **requires** the Ollama addon before it |
| `docker-compose.with-litellm.yml` | Local LiteLLM gateway (routes from `litellm-config.yml`) | `.env.litellm` fragment |
| `docker-compose.with-temporal-ui.yml` | Temporal web UI (host binding `127.0.0.1:8081`) | — |
| `docker-compose.scraper.yml` | Website scraper container | `.env.scraper` fragment |

- Values: file paths relative to `_docker_production/`, colon-separated,
  order significant (later files override earlier ones; the GPU addon must
  follow the Ollama addon). Default: `docker-compose.yml` alone.
- Compose reads the variable from `.env`, so `./deploy.sh` picks it up — no
  shell export needed. Setting it per-invocation
  (`COMPOSE_FILE=… ./deploy.sh`) works too.
- Adding a compose file does not configure the application side: copy the
  matching env fragment (below) and, for LiteLLM, edit `litellm-config.yml`.

## How the environment is assembled

`.env.example` is the core every deployment needs; provider and addon
knowledge lives in fragments copied next to `.env`:

```bash
cd _docker_production
cp .env.example .env                # core: secrets + composition + wiring
cp .env.ollama.example  .env.ollama # optional: Ollama model serving
cp .env.litellm.example .env.litellm # optional: LiteLLM gateway credentials
cp .env.scraper.example .env.scraper # optional: website scraper addon
./generate-env-secrets.sh           # prints the five required secrets
./deploy.sh
```

- Every compose service carries the four files in its `env_file` list
  (`.env` required; fragments optional, skipped when absent). `deploy.sh`
  additionally passes each present fragment as `--env-file`, so variables the
  compose files interpolate themselves (image tags, ports) resolve from
  fragments too; when running `docker compose` directly, add the same flags.
- `${...}` references inside a fragment (e.g.
  `SCRAPE_STORAGE_PATH=${SHARED_STORAGE_ROOT}`) resolve against the core
  `.env`: Compose interpolates env-file values from the project environment,
  and the `env_file` order only decides which file wins on duplicate keys.
  Fragments must not redefine core variables.
- Variables absent from the files fall back to code/image defaults — every
  one of them is documented in the tables below.
- The five secrets (`APP_KEY`, `DB_PASSWORD`, `NEO4J_PASSWORD`,
  `HAWKI_RAG_WORKER_CALLBACK_SECRET`, `FILE_CONVERTER_TOKEN`) are
  **non-rotatable after first start**: a changed `APP_KEY` makes encrypted
  sessions unreadable, and new database passwords do not rotate accounts
  inside existing volumes. Hex secrets keep the
  `NEO4J_AUTH=${NEO4J_USER}/${NEO4J_PASSWORD}` interpolation safe.

## Runtime options and system configuration

**Runtime options** are values supplied through supported requests or the Settings
UI while services are running. **System configuration** is the environment,
service setup, and stored dataset configuration that operators manage through
deployment or migration.

| Kind of change | Can it be changed during normal operation? | When it takes effect and what to preserve |
|---|---|---|
| Document text/metadata and query options such as `top_k`, `generate`, and `fast_mode` | Yes, through the documented API | Applies to that submission or query; a document revision uses a new idempotency key |
| Supported chat/graph/vision model selections in Settings | Yes, select an available model through Settings | New requests or workflow inputs use the selection; existing graph facts retain their previous extraction results |
| Default embedding provider/model in Settings | Yes, as a default for new datasets | Existing datasets keep their stored embedding provider/model; changing an existing dataset's embedding space requires a new target and re-ingestion |
| A request's `graph` option on supported managed-document/upload routes | Yes, when creating or updating work through those routes | Applies to that ingestion operation; direct-text ingestion always sets `graph=false` |
| Environment values for batching, timeouts, reranking, or retrieval limits | Apply through a controlled deployment | Recreate the services that read the values; existing vectors remain valid, and saved workflow inputs can retain earlier options |
| Chunking rules, embedding space, collection distance, or graph extraction limits | Apply as a planned indexing change | Coordinate active ingestion, apply configuration, and rebuild affected data; use a new compatible collection for an embedding-space or distance change |
| Database accounts, storage locations, service routing, or `APP_KEY` | Apply through planned maintenance or migration | Coordinate persistent state, dependent services, and in-flight work before switching |

A request option and an environment default have different lifecycles. For
example, `generate` can vary per query, while `RAG_GENERATE_ANSWER` is a deployment
setting that controls whether generation is available. Changing `.env` takes
effect when the affected containers are recreated and Laravel's configuration
cache is refreshed.

## Before changing a value

| Change | Recreate? | Data impact |
|---|---|---|
| URL, timeout, retry setting | Recreate consumers | Usually none; existing workflow input may retain old options |
| Chat/vision model | Recreate consumers or use supported Settings | Existing graph facts do not change automatically |
| Embedding model/provider or chunking | Recreate; plan a rebuild | Existing vectors are not converted; ordinary unchanged sources may skip |
| Database credentials | Rotate persistent account, then recreate consumers | Editing dotenv alone does not rotate existing database users |
| `APP_KEY` | Recreate Laravel after deliberate rotation | Encrypted state may become unreadable |
| Shared storage root | Coordinate mounts and consumers | Existing artifact references may become invalid |

A container restart alone does not reload Compose's environment; recreate
the services (`deploy.sh` does). Keep the embedding model and storage targets
compatible for active datasets, and indexing rules stable during failure
recovery — see
[chunking & embeddings](../Core%20Concepts/Ingestion/chunking_embeddings.md):
embedding compatibility is a dataset invariant; a new default does not
migrate a populated collection.

Entries marked **(template)** are set in the env files; everything else is a
code default you can opt into. **Dormant** entries are defined in code but
consumed by nothing — setting them has no effect today (summary at the end).

## Required secrets (template)

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `APP_KEY` | Laravel AES-256-CBC key for the encrypter, encrypted sessions and stored values; the framework refuses to boot without it | `base64:` + base64 of 32 random bytes; no default | Always, once |
| `DB_PASSWORD` | Laravel's PostgreSQL password; interpolates into `POSTGRES_PASSWORD`/`POSTGRES_PWD`, so one value provisions the server account and authenticates the app | Hex recommended (`openssl rand -hex 24`); default `''` | Always, once |
| `NEO4J_PASSWORD` | Neo4j password: provisions the server account (via `NEO4J_AUTH`) and authenticates Laravel's HTTP clients and the Python Bolt driver | Hex (safe inside the `user/password` interpolation); Laravel default `''` | Always, once |
| `HAWKI_RAG_WORKER_CALLBACK_SECRET` | HMAC-SHA256 secret for both worker↔Laravel directions: Laravel signs bridge control calls, workers sign status callbacks (`v1=` hex over `timestamp.body`, ±300 s window). Converter/indexer workers refuse to start without it | `openssl rand -hex 32`; default `''` | Always |
| `FILE_CONVERTER_TOKEN` | Bearer token for the file-converter container (compose passes it as `F_API_KEY`); compose fails at startup when empty | Hex; default `''` | Always |
| `EXTERNAL_CONVERTER_TOKEN` | Same token under the alternate name, read *first* by Laravel and the converter worker | Alias ref of `FILE_CONVERTER_TOKEN` | Never edit separately |

Delivery details, rotation, and the callback protocol are owned by
[Temporal Operations](./temporal_operations.md#signed-worker-callbacks).

## Deployment (template)

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `HAWKI_RAG_VERSION` | Tag suffix on every pulled stack image (`laravel-`, `bridge-`, `rerank-`, `*-worker-…`); with `deploy.sh`'s `--pull always`, bumping it upgrades the stack | Published tag; `latest` | Pin for reproducible deploys |
| `HAWKI_RAG_IMAGE_REPOSITORY` | Registry namespace of all stack images | `digitalenvironments/hawki-rag` | Dokploy/ghcr.io or mirrors |
| `HAWKI_RAG_APP_PORT` | Host port the Laravel nginx container publishes | Port; `8080` | Host port collision |
| `DOCKER_PROJECT_IP` | Host interface the published port binds on | IP; `127.0.0.1` (loopback only) | Expose beyond local proxy |
| `DOCKER_PROJECT_HOST` | Public `host:port` browsers use; feeds `APP_URL` (a wrong value breaks generated links, not routing) | Template `localhost:${HAWKI_RAG_APP_PORT}` | Always |
| `DOCKER_PROJECT_PATH` | Base path for subpath deploys; Laravel prefixes Vite asset URLs with it and Vite bakes it into the build — build-time and runtime values must match or assets 404 | `/` | Subpath deployments only |
| `DOCKER_PROJECT_PROTOCOL` | Public scheme feeding `APP_URL` | `http`; `https` behind TLS | TLS proxy |
| `APP_URL` | Canonical public URL: forced URL-generator origin, storage URL, mail EHLO, MCP base default | URL; `http://localhost` | Always |
| `APP_NAME` | Display name; default cache-key slug source | `HAWKI RAG` | Always |

## Application, PHP, logging, sessions

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `APP_MAINTENANCE_STORE` **(template)** | Cache store consulted for maintenance state | Default `database`, template pins `file` | Never (stack choice) |
| `APP_ENV` | Environment name; **side effect:** flips `SESSION_SECURE_COOKIE` default on in production | `production` / `staging` / `local` / `testing` | Non-prod deploys |
| `APP_DEBUG` | Verbose error pages with stack traces | Bool; `false` | Debugging only |
| `APP_TIMEZONE`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE`, `APP_MAINTENANCE_DRIVER` | Standard Laravel settings | `UTC`, `en`, `en`, `en_US`, `file` | Rarely |
| `CACHE_PREFIX` **(template)** | Namespace for every cache key; empty disables the prefix | Default `slug(APP_NAME)_cache_` | Template pins empty |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_DEPRECATIONS_CHANNEL` / `LOG_LEVEL` | Log routing: default channel; channels aggregated by `stack`; deprecation sink; severity (`debug…emergency`) | `stack` / `single` / `null` / `debug` | Route logs elsewhere |
| `QUEUE_CONNECTION` / `DB_QUEUE_CONNECTION` / `QUEUE_FAILED_DRIVER` | Queue backend, its DB connection, failed-job storage | `database` / default connection / `database-uuids` | Switch backends |
| `CACHE_STORE` / `DB_CACHE_CONNECTION` / `DB_CACHE_TABLE` / `DB_CACHE_LOCK_CONNECTION` / `DB_CACHE_LOCK_TABLE` | Cache store, connection, tables | `database` / default conn / `cache` / store conn / `cache_locks` | Switch backends |
| `SESSION_DRIVER` … `SESSION_DOMAIN` | Session backend, connection, table, lifetime, encryption, cookie flags | `database` / default conn / `sessions` / `120` / `true` / `true` / `lax` / `/` / `null` | Rarely |
| `SESSION_SECURE_COOKIE` **(template)** | HTTPS-only session cookie; default flips **true** when `APP_ENV=production` — template pins `false` because TLS terminates upstream | Bool | Direct TLS only |
| `SESSION_EXPIRE_ON_CLOSE` **(template)** | Session dies with the browser | Bool; default `false`, template `true` | Stricter posture |
| `PHP_CLI_SERVER_WORKERS`, `BCRYPT_ROUNDS`, `BROADCAST_CONNECTION`, `MEMCACHED_HOST` | **Dormant** — see the summary section | — | Nothing today |

## PostgreSQL and Laravel state

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` **(template)** | Laravel DB wiring; code defaults (`127.0.0.1`/`laravel`/`root`) are wrong inside the network | `postgres` / `hawki_rag` / `rag_user` | Always |
| `DB_CONNECTION` / `DB_PORT` | Driver and port | `pgsql` / `5432` (defaults) | Rarely |
| `HAWKI_RAG_MONITOR_RETENTION_DAYS` | Days monitor evidence is kept; `<1` keeps everything | Int; `30` | Retention tuning |

Adapter variables `POSTGRES_DB`/`POSTGRES_USER`/`POSTGRES_PASSWORD` reference
the canonical `DB_*` values; Temporal's image additionally uses
`POSTGRES_PWD`, `POSTGRES_SEEDS=postgres` and `DB=postgres12` — changing
`DB_HOST` alone does not reconfigure Temporal persistence. Laravel's
database job queue is unrelated to Temporal's task queues; Python code never
touches application PostgreSQL tables (shared env injection still makes the
variables visible in Python containers).

## Shared storage and worker identity

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `SHARED_STORAGE_ROOT` **(template)** | Pure anchor — no code reads it; Compose expands it into every path below and in the fragments | `/shared` | Relocate the shared root in one place |
| `HAWKI_RAG_PIPELINE_ROOT`, `HAWKI_RAG_CRAWLED_DATA_ROOT`, `DEFAULT_CRAWLED_ROOT` **(template)** | Laravel pipeline/crawled roots (health check, upload roots, entrypoint `mkdir`/`chown`); equal their defaults, follow the anchor | `/shared` chain | Never separately |
| `HAWKI_RAG_TEMPORAL_SHARED_ROOT` **(template)** | Canonical shared root for Temporal artifact hand-off — travels **inside workflow payloads** to the workers | `/shared` | Never separately |
| `HAWKI_RAG_RAGANYTHING_LOG_PATH` **(template)** | First candidate Laravel probes for the RAG-Anything runtime log (the bridge writes it) | Fallbacks incl. `<shared>/logs/raganything_runtime.log` | Follows anchor |
| `HAWKI_RAG_TEMPORAL_{SCRAPER,CONVERTER,INDEXER}_LOG_PATH` **(template)** | Stage runtime logs: workers attach file handlers here, Laravel reads the same files for the monitor view | Worker fallback `<shared>/logs/<stage>_worker.log` | Follow anchor |
| `PIPELINE_SHARED_STORAGE_WEB_USER` | User the health check validates shared-path writability for | `www-data` | Non-default fpm user |
| `PUID` / `PGID` (+`PIPELINE_SHARED_STORAGE_UID/GID`) **(template)** | Host identity for the shared volume: the Laravel entrypoint **requires** UID/GID, `chown`s the crawled root, workers get the group via `group_add: ${PGID}` (no compose fallback) | `33` (www-data) | Always |
| `HAWKI_RAG_WORKER_CALLBACK_URL` **(template)** | Endpoint workers POST signed events to; the **scraper worker's default is empty and raises at load** | `http://hawki_rag_app/api/internal/pipeline/worker-events` | Laravel not at `hawki_rag_app` |
| `HAWKI_RAG_WORKER_CALLBACK_MAX_AGE_SECONDS` | Anti-replay window for callback timestamps; `<1` disables verification | Seconds; `300` | Clock-skew tuning |

The pre-Temporal `HAWKI_RAG_INGEST_LOG_PATH` (+`_CACHE`/`_NEO4J`) variables
were removed from the templates — nothing has read them since the Temporal
migration.

## Temporal orchestration

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `TEMPORAL_NAMESPACE` **(template)** | Namespace bridge + workers connect to; feeds `DEFAULT_NAMESPACE` for the server image | `default` | Tenant separation |
| `TEMPORAL_UI_CORS_ORIGINS` **(template)** | CORS the Temporal *server* allows (UI behind a proxy); feeds `TEMPORAL_CORS_ORIGINS` | `http://localhost:8080` | Proxy origin differs |
| `TEMPORAL_WORKFLOW_EXECUTION_TIMEOUT` **(template)** | Total workflow lifetime cap the bridge sets on start/schedule | Seconds; bridge default `172800` (48 h) | Long crawls |
| `TEMPORAL_WORKFLOW_RUN_TIMEOUT` **(template)** | Cap for a single run | Seconds; `86400` (24 h) | Long runs |
| `HAWKI_RAG_TEMPORAL_ENABLED` | Laravel kill-switch for orchestrated ingestion | Bool; `true` | Freeze pipelines |
| `TEMPORAL_ADDRESS` | gRPC host:port of the Temporal server | `temporal:7233` | External server |
| `TEMPORAL_INGEST_WORKFLOW_TYPE` | Workflow type the bridge starts | `IngestSourceWorkflow` | Renamed workflow only |
| `TEMPORAL_WORKFLOW_TASK_TIMEOUT` | Per-workflow-task timeout | Seconds; `30` | Never in practice |
| `TEMPORAL_RAG_WORKFLOW_TASK_QUEUE` … `TEMPORAL_RAG_INDEXER_TASK_QUEUE` | Task queues: workflow worker polls the first; scraper/converter workers poll theirs; indexer polls `INDEXER` with the legacy `INGESTION` queue as fallback while old histories drain | `rag-workflow-task-queue`, `rag-scraper-task-queue`, `rag-converter-task-queue`, `rag-ingestion-task-queue` | Splitting worker pools |
| `TEMPORAL_RAG_HTTP_TIMEOUT_SECONDS` | Per-request timeout for worker→crawler/converter HTTP calls (start + status polls) | Float s; `1800` | Slow external services |
| `TEMPORAL_RAG_HTTP_RETRY_ATTEMPTS` | Attempts per HTTP call, exponential backoff capped 10 s | Int ≥1; `3` | Flaky endpoints |
| `TEMPORAL_RAG_EXTERNAL_POLL_INTERVAL_SECONDS` | Sleep between status polls of a crawl/convert job | Float s ≥0.5; `5` | Poll-load tuning |
| `TEMPORAL_RAG_EXTERNAL_POLL_TIMEOUT_SECONDS` | Overall deadline for one start-and-poll job before it fails as `timeout` | Float s; `43200` (12 h) | Bound job runtime |
| `RAG_INGEST_GRAPH` | Default graph-extraction flag written into workflow input (per-request override wins; direct-text stays graph-off) | Bool; `false` | Enable graph ingestion |
| `TEMPORAL_UI_HOST_BIND` / `TEMPORAL_UI_HOST_PORT` | Host binding of the Temporal UI addon (container listens on 8080 internally) | `127.0.0.1` / `8081` | Expose UI / port clash |
| `TEMPORAL_CLIENT_IDENTITY`, `TEMPORAL_RAG_{DAILY,WEEKLY,MONTHLY}_CRON` | **Dormant** — the bridge hard-codes the crons (`0 2 * * *`, `0 2 * * 0`, `0 2 1 * *` UTC) | — | Edit the bridge instead |

## Scraper and file converter

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `FILE_CONVERTER_BASE_URL` **(template)** | Converter base URL for Laravel and the converter worker; the code default wrongly uses port 8000, the container listens on 80 | `http://file-converter` | Always in this stack |
| `CUSTOM_CONVERTER_STATUS_PATH` **(template)** | Job-status path template written into upload profiles and polled for async converters | Chain default `/jobs/{job_id}` | Non-default status path |
| `FILE_CONVERTER_HEALTH_URL` | Health endpoint probed by the worker health check | Derived `base + /health` | Custom health path |
| `EXTERNAL_CONVERTER_URL` / `_START_PATH` / `_STATUS_PATH` | Preferred converter base, extract path (POST multipart → ZIP), status path — empty status path means synchronous extraction | base ref / `/extract` / `''` | Async/external converters |
| `FILE_CONVERTER_RETRIES` / `FILE_CONVERTER_SUPPORTED_EXTENSIONS` | Retry budget shown in the status UI; extension allowlist for the custom-converter path | `3` / `''` | Alignment / extra formats |
| `CUSTOM_CRAWLER_URL` **(fragment)** | Crawler base URL for Laravel clients and the scraper worker | `http://crawl4ai-service` | `http://scraper` with the addon |
| `CUSTOM_CRAWLER_TASK_UI_URL` / `EXTERNAL_SCRAPER_URL` **(fragment)** | UI proxy base (Laravel proxies `/ui/*`); scrape job base — empty disables scraping in Laravel's health view | Fall back to `CUSTOM_CRAWLER_URL` | With the addon |
| `EXTERNAL_SCRAPER_START_PATH` / `EXTERNAL_SCRAPER_STATUS_PATH` / `EXTERNAL_SCRAPER_TOKEN` / `CUSTOM_CRAWLER_API_KEY` | Scraper worker call paths and optional bearer tokens (unset = unauthenticated crawler) | `/crawl` / `/status/{job_id}` / `''` / `''` | Authenticated/external crawlers |
| `ORIGIN` **(fragment)** | Read by the external scraper image (not this repo): its SvelteKit UI validates browser Origin against this public URL | Public URL | Behind the HAWKI proxy |
| `HAWKI_RAG_SCRAPER_VERSION` / `HAWKI_RAG_SCRAPER_IMAGE_REPOSITORY` | Scraper addon image tag/repository — compose-YAML interpolation (needs the fragment passed via `--env-file`) | `latest` / `digitalenvironments/hawki-rag-scraper` | Pin/mirror |
| `MAX_CONCURRENT_TASKS` / `DATA_PATH` | Read by the external scraper image only: crawl concurrency; output dir (keep inside the shared volume mount) | Image defaults | Tuning |
| `SCRAPE_STORAGE_PATH` **(fragment)** | Crawl-data root the shared-storage health check validates | Chain default `/shared` | Follows anchor |
| `SCRAPE_ALLOWED_LOCAL_ROOTS`, `SCRAPE_DEFAULT_{MAX_PAGES,CONCURRENCY,MAX_RPM,SKIP_IMAGES,DISCOVERY_MODE}` | **Dormant** — crawler behavior comes from task profiles and request metadata (fallbacks 100/1/60/false/false) | — | Nothing today |

Prefer explicit `EXTERNAL_*` settings for worker calls; workers fall back to
Laravel's crawler/converter variables when theirs are blank. Changing URLs
does not start those services — startup is owned by
[Setup](../Getting%20Started/2_setup.md#external-tools); polling and retry
budgets live in [Temporal Operations](./temporal_operations.md).

## Model runtime: provider, gateway, models

Routing: with `RAG_PROVIDER=ollama` the app speaks Ollama-native to
`OLLAMA_API_URL` (bare names get `ollama_chat/`/`ollama/` prefixes). With
`RAG_PROVIDER=litellm` **every** call goes to `LITELLM_API_URL` using the
OpenAI-compatible transport — the model string you configured travels
verbatim as the gateway's deployment name, so the gateway must resolve it
(`ollama*/…` names imply a gateway route pointing at an Ollama server).
Model selections travel per request from Laravel (Settings defaults come
from these variables); a dataset keeps the embedding model it was created
with.

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `RAG_PROVIDER` **(template)** | The single runtime switch; unset/unknown fails loud on both sides | `ollama` or `litellm` | Always |
| `RAG_GENERATE_ANSWER` **(template)** | Bridge gate for answer synthesis (request `generate` must also be true and context non-empty); **code default false** | Bool; template `true` | Keep answers on |
| `LITELLM_API_URL` **(template)** | Gateway endpoint for `RAG_PROVIDER=litellm`; no Python default — unset sends calls to OpenAI's public endpoint | `http://litellm:4000/v1` | Gateway serving |
| `LITELLM_API_KEY` **(template)** | Bearer token to the gateway; doubles as the local addon's master key (images refuse to boot without one; unset falls back to the shared `sk-noauth` placeholder) | Secret | Gateway with auth / addon |
| `GRAPH_EMBEDDING_DIMENSIONS` **(template)** | Trusted `model=dimension` map for graph-only ingestion (no embedding response to measure); appended after built-ins, later keys override; part of the graph cache key | `name=dim` CSV; built-ins `ollama/bge-m3=1024,openai/text-embedding-3-small=1536` | Custom embedding models |
| `EMBED_MODEL` **(template)** | Canonical embedding model; pinned per dataset at creation | Prefixed name or bare alias; `ollama/bge-m3` | Always |
| `CHAT_MODEL` **(template)** | Chat model for answer generation, query rewrite, graph extraction | `ollama_chat/llama3.1:8b` | Always |
| `VISION_MODEL` **(template)** | Vision model describing images during graph ingestion and warmup; the query contract requires a non-empty value even when never invoked | Vision-capable name; `ollama_chat/qwen2.5vl:7b` | Always |
| `EMBED_MODELS` / `CHAT_MODELS` / `VISION_MODELS` | Allowlists merged after the singulars: Settings-UI dropdowns, capability enforcement, and (dev addon) rendered gateway routes | CSV; default = the singular | Offer more models |
| `GRAPH_RAG_MODEL` | Laravel-only graph-extraction model override (travels per request) | Default `CHAT_MODEL` | Stronger extraction model |
| `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` **(fragment)** | Upstream credentials the gateway routes read via `os.environ` — empty routes stay listed and fail closed | Secrets | Hosted routes via addon |
| `OPENAI_API_BASE` / `ANTHROPIC_API_BASE` | Standard-endpoint overrides for corporate proxies, injected into matching gateway routes | URLs | Proxy routing |
| `LITELLM_IMAGE` / `LITELLM_PORT` | Local addon container image / host loopback port (container always listens on 4000; in-network callers use `http://litellm:4000/v1` regardless) | `main-stable` / `4000` | Pin image / port clash |
| `HAWKI_RAG_BRIDGE_URL` | Laravel→bridge base for `/query`, `/health`, workflow endpoints | `http://hawki_rag_bridge` | Custom networking |
| `HAWKI_RAG_QUERY_TIMEOUT` | Laravel's HTTP timeout proxying queries; on timeout a 502 is returned. MCP has its own fixed 60-second bridge timeout | Seconds ≥1; `300` | Slow generations |
| `OLLAMA_NUM_PREDICT` **(template)** | `max_tokens` for every chat/vision call (extraction output cap) | Tokens; `900` (template 256) | Cost control |
| `MODEL_WARMUP_ENABLED` / `_SCHEDULE_CRON` / `_SCHEDULE_TZ` / `_QUERY` / `_INCLUDE_VISION` / `_QDRANT_COLLECTION` / `_DATASET_ID` | Warmup workflow: daily schedule + startup run; probe text for chat/embed/vision and the end-to-end retrieval query; vision inclusion; collection pin (empty auto-picks the first existing collection, skips gracefully when none exist) | `true` / `0 6 * * *` / `Europe/Berlin` / `"Reply with OK."` / `true` / `""` / `model-warmup` | Tune warmup behavior |

## Ollama serving (fragment)

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `OLLAMA_API_URL` **(fragment)** | API root for direct Ollama calls (trailing `/api` stripped); default host `ollama` mismatches this stack's container | `http://hawki_ollama:11434/api` | Always with Ollama |
| `OLLAMA_CHAT_TIMEOUT` **(fragment)** | Timeout for chat/vision completions | Seconds; `120` (template 240) | Large local models |
| `OLLAMA_KEEP_ALIVE` **(fragment)** | Model residency after a request, sent per call and applied as the server default via the addon | Ollama duration string (`24h`, `5m`, `-1`=forever); unset = short idle unload | Keep models warm |
| `HAWKI_RAG_OLLAMA_MODELS` **(fragment)** | Space-separated models the entrypoint pulls at container start (failures warn, never abort) | `bge-m3 llama3.1:8b llama3.2:1b qwen2.5vl:7b` | Trim on CPU hosts |
| `OLLAMA_EMBED_NAN_ZERO_FALLBACK` | Replace NaN embedding vectors with zeros instead of failing the call | Bool; `true` | Set false to fail loud |
| `GPU_MIN_FREE_MB` | VRAM guard: below this free VRAM the entrypoint disables GPU (CPU degradation instead of starving GPU tenants) | MB; `4096` | Shared-GPU hosts |
| `OLLAMA_NUM_GPU` / `OLLAMA_GPU_LAYERS` | **Dormant** — GPU enablement happens via the GPU addon's device reservation | — | Nothing today |

## Bridge, query, and indexing defaults

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `HAWKI_RAG_HEALTH_GATE_ENABLED` / `_TIMEOUT` / `_REQUIRED` | System gate: `ready`/`blocked` based on required component checks; per-check timeout; required-component subset | `true` / `3` / `retrieval,graph,pipeline` | Exclude graph when unused |
| `HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT` | `true`: all active datasets queryable without explicit grants (single-user); `false`: explicit grants only; never affects ingestion | Bool; `true` | Multi-tenant |
| `SANCTUM_ROUTES` / `SANCTUM_EXPIRATION` / `SANCTUM_TOKEN_PREFIX` | Sanctum SPA routes; token TTL (null = never — deliberate, TTL expiry turned off); token prefix for secret scanning | `false` / `null` / `hawki_rag_` | SPA front-end / token TTL |
| `HAWKI_RAG_CORS_ALLOWED_ORIGINS` | Browser origins allowed to call `/api/*` cross-origin | CSV; `''` = none | External browser UI |
| `INGEST_BATCH_SIZE` | Files per ingestion group **and** chunk-points per Qdrant upsert batch | Int >0; `64` | Throughput tuning |
| `CHUNK_SIZE` / `CHUNK_OVERLAP_SIZE` | Chunk characters / overlap; overlap must be `< CHUNK_SIZE`; flows into workflow payloads and is applied by the indexer | `1200` / `250` | Retrieval granularity (reindex) |
| `QDRANT_DISTANCE` | Distance metric for new collections; existing collections must match or be rebuilt | `Cosine`/`Dot`/`Euclid`; `Cosine` | Metric change (reindex) |
| `STARTUP_CHECKS_ENABLED` / `_ATTEMPTS` / `_BACKOFF_SECONDS` | Bridge startup verification: ping Qdrant + Neo4j before serving, retry with doubling backoff (cap 30 s); failure aborts startup | `false` / `3` / `0.5` | Prod fail-fast |
| `STARTUP_CHECK_TIMEOUT_SECONDS` | **Dormant** (use `QDRANT_TIMEOUT` to influence the ping) | — | Nothing today |

<details>
<summary>Advanced query tuning: code defaults</summary>

These absent-from-template variables are read by the bridge. Recreate it after
changing them; they affect retrieval behavior, not stored embeddings.

| Variable | Code default |
|---|---|
| `RAG_SEARCH_TOP_K_MULT`, `RAG_SEARCH_TOP_K_CAP` | `3`, `50` |
| `RAG_FUSION_SEM_WEIGHT`, `RAG_FUSION_STR_WEIGHT` | `0.6`, `0.4` |
| `RAG_MIN_SCORE`, `RAG_MIN_SCORE_FALLBACK` | `0.1`, `0.2` |
| `RAG_CONTEXT_TOKENS`, `RAG_CONTEXT_DOCS` | `2800`, `6` |
| `RAG_ITERATIVE_RETRIEVAL` | `true` |
| `RAG_STRUCTURAL_HOPS` | `2` |
| `RAG_GRAPH_TERMS_PER_HIT`, `RAG_GRAPH_TERM_LIMIT` | `12`, `30` |
| `QDRANT_TEXT_SCROLL_LIMIT`, `RAG_EXHAUSTIVE_TEXT` | `200`, `false` |

See [Query & Retrieval](../Core%20Concepts/query_retrieval.md) before tuning:
thresholds do not guarantee abstention, and the context budget is approximate.

</details>

## Vector and graph stores

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `QDRANT_COLLECTION` **(template)** | Default collection for generic clients — **not** the selector for authorized queries (dataset scope owns that); unset makes the Python client auto-pick the most populated collection | Template `hawki_docs` | Pin one collection |
| `QDRANT_API_KEY` **(template)** | `api-key` header on every Qdrant request (both sides) — a client credential, it does not enable server auth | Secret; unset = none | Secured/Qdrant Cloud |
| `QDRANT_HTTP_URL` | Laravel-side base URL (Python builds `SCHEME://HOST:PORT` instead — set both sides when they differ) | `http://qdrant:6333` | Remote/proxied Qdrant |
| `QDRANT_SCHEME` / `QDRANT_HOST` / `QDRANT_PORT` | Python-side URL parts (Laravel uses them only as health-check fallback) | `http` / `qdrant` (Python) vs `127.0.0.1` (Laravel) / `6333` | Custom networking |
| `QDRANT_TIMEOUT` | Base per-request timeout; all per-operation timeouts derive from it | Seconds; `30.0` | Large upserts/slow Qdrant |
| `QDRANT_RETRY_ATTEMPTS` | Retry budget seeding per-operation maps (`QDRANT_RETRY_ATTEMPTS_{UPSERT,DELETE,COUNT,SEARCH,SCROLL,READ,COLLECTION_CREATE}`); retries on 429/5xx/timeouts with 0.5→5 s backoff | Int ≥1; `3` | Flaky networks |
| `QDRANT_TEXT_FALLBACK_TERMS` | Max query terms used in lexical `match.text` fallback filters | Int; `3` | Keyword-heavy queries |
| `NEO4J_HTTP_URL` **(template)** | HTTP endpoint for **Laravel only** (queries, stats, health); in the worker it is only a fallback to synthesize a Bolt URI | `http://hawki_rag_neo4j:7474` | Always |
| `NEO4J_URI` **(template)** | Bolt endpoint for the **Python side only** (graph store, LightRAG, cleanup); default host `neo4j` mismatches the container name; must not leak into the Neo4j server container | `bolt://hawki_rag_neo4j:7687` | Always |
| `NEO4J_USER` **(template)** | Account for both sides (`NEO4J_USERNAME` is a compatibility fallback); referenced by `NEO4J_AUTH` | `neo4j` | Always |
| `NEO4J_DATABASE` **(template)** | Physical database for Python session kwargs; empty = server default (distinct from the dataset namespace) | Deliberately empty | Named graph database |
| `NEO4J_BOLT_URL` | Second-priority fallback for the worker's connection URI — effectively legacy | `''` | Never in practice |
| `NEO4J_MAX_TRANSACTION_RETRY_TIME` | Driver retry window for managed transactions | Seconds; `30.0` | Contention tuning |

## Graph extraction (RAG-Anything / LightRAG)

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `GRAPH_DOC_MAX_CHUNKS` / `GRAPH_DOC_MAX_CHARS` **(template)** | Per-document extraction caps: chunks entering entity extraction (first N), and the character budget (chunk crossing the limit is cut); rebuild the graph to apply to existing facts | Int; `0`/unset = unlimited (template 6 / 6000) | Cost ceiling per doc |
| `GRAPH_MIN_CHUNK_CHARS` / `GRAPH_MIN_DOC_CHARS` **(template)** | Loaded and reported in runtime summaries but **not enforced** today (diagnostics-only) | Int; unset = `None` | Currently cosmetic |
| `GRAPH_RESET_CACHE_PER_DOC` | Clear all LightRAG caches and recreate the client before each document (no cross-doc LLM-cache reuse) | Bool; **default true** | `false` for cheaper re-runs |
| `GRAPH_TEMPERATURE` | Sampling temperature for extraction chat/vision calls; part of the client cache key | Numeric; unset = `0.0` | Rarely |
| `GRAPH_ENGINE` | Declared engine — the Python layer enforces `raganything` regardless | `raganything` | Label only |
| `RAG_WORKING_DIR` | LightRAG runtime state dir (KV stores, LLM cache, parser output); intermediate files, not the shared artifact root | `/app/rag_storage` | Volume mapping |
| `GRAPH_PERF_LOG` | `perf:graph` timing logs across ingestion, extraction, and Neo4j writes | Bool; `false` | Profiling |
| `GRAPH_EMBED_JUNK_STRICT` | Strict junk filter (boilerplate + German form placeholders) before embedding and on stored labels — junk gets a sentinel vector instead of an API call | Bool; **default true** | False if real terms get filtered |
| `GRAPH_EMBED_JUNK_ALLOWLIST` / `_DENYLIST` | Pattern lists evaluated before/after built-in junk rules (`exact:`/`contains:`/`re:` prefixes, comma/semicolon-separated); allowlist wins over everything | CSV; empty = none | Corpus-specific junk/escapes |
| `MAX_ASYNC` / `MAX_PARALLEL_INSERT` **(template)** | LightRAG's own env keys: concurrent LLM calls during extraction (graph merge uses 2×) and parallel document inserts | Int; library defaults `4` / `2` (template 2 / 2) | Rate-limit protection |
| `GRAPH_STRIP_PIPES`, `GRAPH_DISABLE_GLEANING`, `GRAPH_PROMPT_MAX_EXAMPLES`, `KG_MAX_GLEANING`, `KG_LANGUAGE`, `KG_ENTITY_TYPES` | **Dormant as named** — LightRAG reads the unprefixed `MAX_GLEANING` (default 1), `SUMMARY_LANGUAGE` (English), `ENTITY_TYPES` (11 types) instead; setting the `KG_*` names does nothing, setting the unprefixed ones changes behavior vs today | — | See dormant summary |

[Ingestion with Graph Processing Enabled](../Core%20Concepts/Ingestion/graph_enrichment.md)
explains library internals and graph-only repair limitations.

## Reranking

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `RERANKER_MODE` **(template)** | Default mode for requests omitting `reranker`: `none` (skip), `cosine` (re-embed + cosine), `external` (composed client or raw HTTP), `jina` (direct Jina). Internal RagSearcher requests `external` explicitly, so `none` is not a global kill switch | Enum-ish; code default `none`, template `external` | Always (enable reranking) |
| `RERANKER_API_URL` **(template)** | Endpoint POSTed to in `external` mode with no composed client (provider `cohere`): body `{query, documents}`, 30 s timeout; blank skips the branch | URL; no code default | Always with the in-stack reranker |
| `RERANKER_PROVIDER` | Backend composed at bridge startup: `cohere`/`legacy_http` (raw HTTP), `local` (in-process CrossEncoder, needs a specially built bridge image), `litellm` (SDK). Unknown → startup `ValueError` | `cohere` / `local` / `litellm` | Switch backends |
| `RERANKER_MODEL` | SDK rerank model (requires provider `litellm`), validated at startup against rerank-capable providers; `litellm_proxy/` prefix makes the gateway pair mandatory | e.g. `litellm_proxy/jina-rerank`, `cohere/rerank-v3.5`; empty raises under provider litellm | SDK reranking |
| `RERANKER_FALLBACK_MODEL` | One retry attempt on retryable SDK errors; must be another `litellm_proxy/` alias differing from the primary; unset/blank/`none` = off. Authentication/configuration failures never trigger it | `litellm_proxy/…` or off | Flaky gateway route |
| `LITELLM_PROXY_API_BASE` / `_API_KEY` | Gateway pair for `litellm_proxy/` rerank calls (**independent of** `LITELLM_API_URL`/`KEY`); trailing `/v1` stripped automatically; both-or-nothing at startup | URL / secret; empty | SDK reranking via gateway |
| `RERANKER_MIX_MODE` / `RERANKER_MIX_WEIGHT` | Blend normalized retrieval + reranker scores instead of pure reranker order; α = retrieval share in `α·retrieval + (1−α)·reranker`, clamped [0,1] | `true` / `0.5` | Retrieval-quality tuning |
| `RERANKER_API_KEY` | Bearer credential on the raw-HTTP rerank POST | Secret; empty = none | Secured endpoint |
| `HAWKI_RERANKER_MODEL` | CrossEncoder model for bridge-local inference and the standalone rerank service — changing it in production changes both where both consume it | HF id; `mixedbread-ai/mxbai-rerank-base-v1` | Swap local model |
| `HAWKI_RERANKER_PRELOAD` | Standalone service loads the model at startup (falls back to lazy on failure); bridge-local is always lazy | Bool; `true` | Cold-start tuning |
| `JINA_API_KEY` **(template)** | Bearer for direct-Jina mode (`https://api.jina.ai/v1/rerank`); blank skips the branch | Secret | `RERANKER_MODE=jina` |
| `JINA_RERANKER_MODEL` | `model` field for direct-Jina calls | `jina-reranker-v2-base-multilingual` | Different Jina model |
| `QUERY_MODE` | **Dormant** | — | Nothing |

Local inference requires the optional bridge build and provisioned
weights/cache — see the
[bridge configuration and examples](../../python_rag/services/hawki_bridge/README.md#production-reranking-backends)
for eligible errors, per-attempt timeouts, and local build commands.
`LITELLM_CHAT_TIMEOUT`/`LITELLM_EMBED_TIMEOUT` are retained in the dev
template as commented historical examples; current helpers read
`OLLAMA_CHAT_TIMEOUT`/`OLLAMA_EMBED_TIMEOUT`.

## Search, MCP, mail, container adapters

| Variable | What it does | Values / default | Set when |
|---|---|---|---|
| `WEB_SEARCH_PROVIDER` **(template)** | Backend bound for the MCP `web-search-tool`: `tavily` or `brave` (exact, case-sensitive). Empty → resolution **throws** on tool use (not silently disabled); empty keys do not disable local RAG | `tavily`/`brave`; default `''` | Using the web-search tool |
| `TAVILY_SEARCH_API_KEY` / `BRAVE_SEARCH_API_KEY` | Credentials for the selected provider (empty key → every call fails) | Secrets | Matching provider |
| `MCP_SERVER` **(template)** | The only live MCP var: URL path the MCP endpoint is registered at (sanctum + `query` ability); the [server registry](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Mcp/Servers/HawkiRagServer.php) is authoritative | Path; `mcp/hawki_rag`, template `hawki_rag` | Relocate the endpoint |
| `MCP_ENABLED`, `MCP_ROUTE_ENABLED`, `MCP_BASE_URL`, `MCP_TIMEOUT`, `MCP_USE_FALLBACK`, `MCP_INGEST_ENABLED`, `MCP_TOOL_RAG_QUERY`, `MCP_TOOL_RAG_INGEST` | **Dormant** (route registration is file-existence based; tool names come from PHP `#[Name]` attributes; `MCP_TOOL_*` are string tool-name slots) | — | Nothing today |
| `MAIL_MAILER` … `MAIL_FROM_NAME` **(template)** | Standard Laravel mail wiring; **no app code sends mail today** (log mailer default). Port 465 auto-switches the scheme to `smtps` | `log` / null / `127.0.0.1` / `2525` / null / null / `hello@example.com` / `Example` | When mail goes real |
| `AWS_*` | Credentials for the `s3` disk, `sqs` queue, `dynamodb` cache, `ses` mailer — inert until one of those drivers is selected | — | AWS-backed drivers |
| `VITE_APP_NAME`, `ASSET_URL` | **Dormant**: no `import.meta.env` consumer; no `asset_url` config key (assets derive from `APP_URL` + `DOCKER_PROJECT_PATH`) | — | Nothing today |
| `NGINX_CLIENT_MAX_BODY_SIZE` / `PHP_POST_MAX_SIZE` / `PHP_UPLOAD_MAX_FILESIZE` **(template)** | Upload/body limits the container images read at runtime (image defaults 1m/8M/2M); the repo's dev `php-custom.ini` hard-codes 64M for dev-overlay deployments | Size shorthand; `128M` | Larger uploads |
| `POSTGRES_DB` / `POSTGRES_USER` / `POSTGRES_PASSWORD` **(template)** | Official postgres image adapters: database/superuser provisioned at volume init + healthcheck; password required by the image | Refs to `DB_*` | Never separately |
| `POSTGRES_PWD` / `POSTGRES_SEEDS` / `DB` / `BIND_ON_IP` / `DEFAULT_NAMESPACE` / `TEMPORAL_CORS_ORIGINS` **(template)** | temporalio/auto-setup adapters: DB password, seed hosts, persistence plugin (`postgres12`), listener bind, namespace, server CORS | `${DB_PASSWORD}` / `postgres` / `postgres12` / `0.0.0.0` / `${TEMPORAL_NAMESPACE}` / `${TEMPORAL_UI_CORS_ORIGINS}` | Never separately |
| `TZ` **(template)** | Timezone of the Temporal container — when its schedules fire in local time | IANA zone; `Europe/Berlin` | Local cron alignment |
| `HAWKI_RAG_COMPOSE_ENV_FILE` | Dev-stack only: the root compose/Makefile use it to pick the env file; the production compose hard-codes `.env` | `.env` | Dev tooling only |

## Dormant variables — summary

Verified read-by-nothing (config may exist, behavior does not change):
`BCRYPT_ROUNDS`, `BROADCAST_CONNECTION`, `PHP_CLI_SERVER_WORKERS`,
`MEMCACHED_HOST` (extension missing), `TEMPORAL_CLIENT_IDENTITY`,
`TEMPORAL_RAG_{DAILY,WEEKLY,MONTHLY}_CRON` (bridge hard-codes them),
`SCRAPE_ALLOWED_LOCAL_ROOTS`, `SCRAPE_DEFAULT_*`,
`OLLAMA_NUM_GPU`, `OLLAMA_GPU_LAYERS`, `STARTUP_CHECK_TIMEOUT_SECONDS`,
`GRAPH_STRIP_PIPES`, `GRAPH_DISABLE_GLEANING`, `GRAPH_PROMPT_MAX_EXAMPLES`,
`KG_MAX_GLEANING`, `KG_LANGUAGE`, `KG_ENTITY_TYPES` (LightRAG reads
`MAX_GLEANING`/`SUMMARY_LANGUAGE`/`ENTITY_TYPES`), the MCP block except
`MCP_SERVER`, `QUERY_MODE`, `ASSET_URL`, `VITE_APP_NAME`, and the
never-wired `GRAPH_MIN_CHUNK_CHARS`/`GRAPH_MIN_DOC_CHARS` (reported, not
enforced). No variable is labelled deprecated without an actual deprecation
policy; dormant entries may be wired up by future code.
