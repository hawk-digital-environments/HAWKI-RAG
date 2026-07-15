# 5. Environment, Database, Temporal (Step-by-Step)

## Environment (.env) — every key explained

### Application
| Variable | Default Value | Description |
| --- | --- | --- |
| `APP_NAME` | `HAWKI RAG` | Name displayed in the Laravel UI and metadata. |
| `APP_URL` | `http://localhost:8080` | Public URL Laravel uses for generated links; normally replaced by your reverse-proxy domain. |
| `APP_KEY` | _Must be set_ | Laravel encryption/session secret; generate once and keep private. |

### Database services
| Variable | Default Value | Description |
| --- | --- | --- |
| `DB_HOST` | `postgres` | PostgreSQL host used by Laravel app container and Temporal local persistence. |
| `DB_PORT` | `5432` | PostgreSQL port exposed on the internal Docker network. |
| `DB_DATABASE` | `hawki_rag` | PostgreSQL database name for Laravel app metadata tables. |
| `DB_USERNAME` | _From `.env`_ | PostgreSQL username used by Laravel and local Temporal persistence. |
| `DB_PASSWORD` | _From `.env`_ | PostgreSQL password used by Laravel and local Temporal persistence. |
| `NEO4J_HTTP_URL` | `http://hawki_rag_neo4j:7474` | Neo4j HTTP endpoint for graph operations. |
| `NEO4J_USER` | _From `.env`_ | Neo4j login user. |
| `NEO4J_PASSWORD` | _From `.env`_ | Neo4j login password. |
| `QDRANT_HTTP_URL` | `http://qdrant:6333` | Qdrant HTTP endpoint for vector search/indexing. |

### HAWKI RAG endpoints and paths
| Variable | Default Value | Description |
| --- | --- | --- |
| `HAWKI_RAG_BRIDGE_URL` | _From `.env`_ | HAWKI-RAG bridge base URL used for query, ingest, health, and graph cache operations. |
| `HAWKI_RAG_TEMPORAL_SHARED_ROOT` | `/shared` | Shared files path used for Temporal scraper/converter/ingestion handoff. |

### Temporal ingestion
| Variable | Default Value | Description |
| --- | --- | --- |
| `TEMPORAL_ADDRESS` | `temporal:7233` | Temporal frontend address from Docker containers. Do not use `localhost:7233` inside containers. |
| `TEMPORAL_NAMESPACE` | `default` | Local Temporal namespace. |
| `TEMPORAL_RAG_WORKFLOW_TASK_QUEUE` | `rag-workflow-task-queue` | Task queue for `IngestSourceWorkflow`. |
| `TEMPORAL_RAG_SCRAPER_TASK_QUEUE` | `rag-scraper-task-queue` | Task queue for `scrape_source`. |
| `TEMPORAL_RAG_CONVERTER_TASK_QUEUE` | `rag-converter-task-queue` | Task queue for `inspect_and_convert_files`. |
| `TEMPORAL_RAG_INGESTION_TASK_QUEUE` | `rag-ingestion-task-queue` | Task queue for `ingest_markdown_files` and `mark_source_ready`. |
| `TEMPORAL_RAG_DAILY_CRON` | `0 2 * * *` | Cron used when source refresh cadence is `daily`. |
| `TEMPORAL_RAG_WEEKLY_CRON` | `0 2 * * 0` | Cron used when source refresh cadence is `weekly`. |
| `TEMPORAL_RAG_MONTHLY_CRON` | `0 2 1 * *` | Cron used when source refresh cadence is `monthly`. |
| `EXTERNAL_SCRAPER_URL` | `http://crawler:8000` | External scraper service base URL; worker calls its start/status endpoints. |
| `EXTERNAL_CONVERTER_URL` | `http://file-converter:8000` | External converter service base URL; worker calls its start/status endpoints. |

### LiteLLM gateway and model aliases

HAWKI RAG uses LiteLLM as its only model-provider boundary. Ollama, OpenAI, and
Anthropic are upstream routes owned by the proxy; Laravel and Python select
allowlisted aliases instead of receiving provider credentials.

| Variable | Default Value | Description |
| --- | --- | --- |
| `RAG_DEFAULT_PROVIDER` | `litellm` | Runtime provider used for ingestion and query model calls. |
| `GRAPH_PROVIDER` | `litellm` | Provider used for graph/model reporting. |
| `LITELLM_API_URL` | `http://litellm:4000/v1` | OpenAI-compatible endpoint visible inside the Compose network. |
| `LITELLM_API_KEY` | Empty | Optional bearer token for a LiteLLM deployment that enables proxy authentication. |
| `LITELLM_CHAT_MODEL` | `hawki-ollama-chat` | Default chat and graph alias selected for new requests. |
| `LITELLM_EMBED_MODEL` | `hawki-ollama-embedding` | Default embedding alias captured when a dataset is created. |
| `LITELLM_VISION_MODEL` | `hawki-ollama-vision` | Default vision alias selected for new ingestion work. |
| `LITELLM_CHAT_ALIASES` | Ollama, GPT, Claude aliases | Allowlist accepted by Laravel Settings for chat/graph. |
| `LITELLM_EMBED_ALIASES` | Ollama and OpenAI aliases | Allowlist accepted by Laravel Settings for embeddings. Anthropic has no embedding route. |
| `LITELLM_VISION_ALIASES` | Ollama, GPT, Claude aliases | Allowlist accepted by Laravel Settings for vision. |
| `GRAPH_EMBEDDING_DIMENSIONS` | Local `1024`, OpenAI small `1536` | Trusted alias-to-dimension map used when graph-only ingestion cannot observe a vector response first. |

| Upstream | Target variables | Credential |
| --- | --- | --- |
| Local Ollama | `LITELLM_OLLAMA_API_BASE`, `LITELLM_OLLAMA_CHAT_MODEL`, `LITELLM_OLLAMA_EMBED_MODEL`, `LITELLM_OLLAMA_VISION_MODEL` | None |
| OpenAI | `LITELLM_OPENAI_API_BASE`, `LITELLM_OPENAI_CHAT_MODEL`, `LITELLM_OPENAI_EMBED_MODEL`, `LITELLM_OPENAI_VISION_MODEL` | `OPENAI_API_KEY` |
| Anthropic | `LITELLM_ANTHROPIC_API_BASE`, `LITELLM_ANTHROPIC_CHAT_MODEL`, `LITELLM_ANTHROPIC_VISION_MODEL` | `ANTHROPIC_API_KEY` |

Provider keys belong only in `.env` and are passed to the LiteLLM container.
The Settings UI exposes safe placeholders and configured/not-configured status,
never the secret values. It accepts only aliases from the three allowlists.

:::warning "Embedding compatibility"
    Each dataset stores the embedding alias used to build its vectors. Changing
    the Settings default affects newly created datasets; existing datasets must
    keep their stored alias unless they are intentionally re-ingested.
:::

## Database setup
### Variables to verify before migrating
| Variable | Default Value | Description |
| --- | --- | --- |
| `DB_HOST` | `postgres` | Must resolve from `hawki_rag_app` and Temporal worker containers to the PostgreSQL service. |
| `DB_PORT` | `5432` | Must match the PostgreSQL service port. |
| `DB_DATABASE` | _From `.env`_ | Target database for Laravel migrations. |
| `DB_USERNAME` | _From `.env`_ | User executing Laravel migrations. |
| `DB_PASSWORD` | _From `.env`_ | Password for migration user. |

:::tip "Important"
    Run migrations with `php artisan migrate`; without it, Laravel cannot store jobs/sessions used by the UI.
:::

## Temporal setup

Temporal local development uses PostgreSQL persistence and SQL visibility. Elasticsearch is
not required. Laravel never writes directly to Temporal tables; it uses the Temporal SDK to
start workflows, cancel workflows, and create/update/delete schedules.

Start the stack:

```bash
docker compose up -d postgres temporal temporal-ui hawki_rag_app ollama litellm hawki_rag_bridge qdrant hawki_rag_neo4j
docker compose up -d hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker
```

Smoke-test the gateway from the host:

```bash
curl -fsS http://127.0.0.1:4000/v1/models
curl -fsS http://127.0.0.1:4000/v1/embeddings \
  -H 'Content-Type: application/json' \
  -d '{"model":"hawki-ollama-embedding","input":["HAWKI RAG smoke test"]}'
```

Temporal UI is exposed on `http://localhost:8081` by default.
