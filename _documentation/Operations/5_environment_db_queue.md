---
title: Environment & Configuration
---

# Environment & Configuration

<div className="hero">

Before changing a value, ask whether services must be recreated, data must be
re-ingested, or persistent state is affected.

[Configuring a new installation? Start here](../Getting%20Started/4_installation_zero_to_up.md)

</div>

:::info How to use this reference

This is the configuration reference. First-install secrets belong in
[Installation](../Getting%20Started/4_installation_zero_to_up.md);
queues, timeouts, schedules, and callback protocol belong in
[Temporal Operations](./temporal_operations.md).

:::

## Find the setting you need

<div className="grid-cards">

- <span className="grid-icon">🗄️</span> __Laravel state__
  PostgreSQL, database queues, cache, and sessions.
  [Open section](#postgresql-and-laravel-state)

- <span className="grid-icon">⏱️</span> __Temporal__
  Workflow routing, schedules, retries, and time limits.
  [Open guide](./temporal_operations.md)

- <span className="grid-icon">🔌</span> __Ingestion tools__
  Crawler, converter, endpoints, and authentication.
  [Open section](#external-ingestion-tools)

- <span className="grid-icon">🧠</span> __Models and retrieval__
  Ollama, LiteLLM, embeddings, and reranking.
  [Open section](#model-providers-and-embeddings)

</div>

## How defaults reach a service

[.env.example](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/.env.example) is the installation template.
[Compose](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/docker-compose.yml) injects the selected environment file into
Laravel, bridge, workers, and several infrastructure containers. Their code
reads the subset it uses. Reranker and Qdrant do not inherit that shared env
block; Neo4j uses [a filtered adapter](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/docker/env/neo4j.env).

The template values below are not necessarily code fallbacks. Persisted
Settings selections can override model defaults for new work; a dataset retains
its embedding provider/model. Existing workflow input can retain an earlier
configuration snapshot.

**ACTIVE** means a current control. **COMPATIBILITY** means an alias or retained
setting with limited/no effect on the current path. **ADVANCED** means an
implementation-specific tuning control. No variable is labelled deprecated
without an actual deprecation policy.

## Before changing a value

| Change | Recreate? | Data impact |
|---|---|---|
| URL, timeout, retry setting | Recreate consumers | Usually none; existing workflow input may retain old options |
| Chat/vision model | Recreate consumers or use supported Settings | Existing graph facts do not change automatically |
| Embedding model/provider or chunking | Recreate; plan a rebuild | Existing vectors are not converted; ordinary unchanged sources may skip |
| Database credentials | Rotate persistent account, then recreate consumers | Editing dotenv alone does not rotate existing database users |
| `APP_KEY` | Recreate Laravel after deliberate rotation | Encrypted state may become unreadable |
| Shared storage root | Coordinate mounts and consumers | Existing artifact references may become invalid |

Use the matching [lifecycle mode](../Getting%20Started/2_setup.md#choose-a-startup-mode)
when recreating services. A container restart alone does not reload Compose's
environment. Laravel config cache must also be cleared if configuration remains
cached; the supported migration startup runs `optimize:clear`.

## Application and access

All rows are ACTIVE unless marked otherwise. “Yes” below means recreate the
listed consumers after editing dotenv.

| Variable | Template default | Read by | Change when | Restart? | Data impact |
|---|---|---|---|---|---|
| `APP_NAME` | HAWKI RAG | Laravel | Branding changes | Yes | None |
| `APP_ENV`, `APP_DEBUG` | `local`, `true` | Laravel | Deploying production: use `production`, `false` | Yes | None |
| `APP_URL` | `http://localhost:8080` | Laravel | Public URL/path changes | Yes | Generated URLs |
| `APP_TIMEZONE` | `UTC` | Laravel | Application timezone changes | Yes | Timestamp presentation; Temporal schedules use UTC |
| `APP_KEY` | Placeholder | Laravel | Initial setup / planned rotation | Yes | Encryption |
| `SESSION_SECURE_COOKIE` | `false` | Laravel | HTTPS deployment | Yes | Browser cookies |
| `HAWKI_RAG_BRIDGE_URL` | `http://hawki_rag_bridge` | Laravel | Bridge location changes | Yes | None |
| `HAWKI_RAG_QUERY_TIMEOUT` | `300` seconds | REST query proxy | Query budget changes | Yes | None; MCP has its own fixed 60-second bridge timeout |
| `HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT` | `true` | Laravel | Require explicit query grants | Yes | Changes access policy; never grants ingestion |
| `HAWKI_RAG_HEALTH_GATE_ENABLED` | `true` | Laravel | Change UI/system gate behavior | Yes | None |
| `HAWKI_RAG_HEALTH_GATE_REQUIRED` | `retrieval,graph,pipeline` | Laravel | Select gate components | Yes | None |

See [Authorization](../Core%20Concepts/authorization_dataset_scope.md) for the
actual single-user and management boundaries.

## PostgreSQL and Laravel state

| Variable | Template default | Read by | Change when | Restart? | Data impact |
|---|---|---|---|---|---|
| `DB_CONNECTION` | `pgsql` | Laravel | Custom database deployment | Yes | Schema compatibility |
| `DB_HOST`, `DB_PORT` | `postgres`, `5432` | Laravel | Application database endpoint changes | Yes | Does not relocate data |
| `DB_DATABASE` | `hawki_rag` | Laravel; PostgreSQL adapter | New application database | Yes | Existing data stays in old DB |
| `DB_USERNAME`, `DB_PASSWORD` | `rag_user`, `change_me` | Laravel; image adapters | Initial credentials / rotation | Yes | Persistent account change |
| `QUEUE_CONNECTION` | `database` | Laravel | Deploy another job backend | Yes | Existing queued jobs |
| `DB_QUEUE_CONNECTION`, `QUEUE_FAILED_DRIVER` | `pgsql`, `database-uuids` | Laravel | Queue persistence changes | Yes | Job/failure records |
| `CACHE_STORE`, `DB_CACHE_CONNECTION` | `database`, `pgsql` | Laravel | Cache backend changes | Yes | Cache/locks |
| `SESSION_DRIVER`, `SESSION_CONNECTION` | `database`, `pgsql` | Laravel | Session backend changes | Yes | Sessions |
| `SESSION_LIFETIME` | `120` minutes | Laravel | Idle session policy | Yes | Sessions |
| `HAWKI_RAG_MONITOR_RETENTION_DAYS` | `30` | Laravel | Monitor retention; below 1 disables pruning | Yes | Monitor evidence removed on opportunistic prune |

The dotenv adapter variables `POSTGRES_DB`, `POSTGRES_USER`,
`POSTGRES_PASSWORD`, and `POSTGRES_PWD` reference the canonical `DB_*` values.
Temporal's image uses `POSTGRES_SEEDS=postgres` and `DB=postgres12`.
Changing `DB_HOST` alone does not reconfigure Temporal persistence.

Compose no longer hard-codes all client addresses in an `environment:` block.
Moving to external infrastructure still requires coordinating service dependencies,
networks, image adapters, and persistent data.

Laravel's database job queue is unrelated to Temporal's task queues.
Python code does not access application PostgreSQL tables, but shared dotenv
injection means database variables are present in Python container environments.

## Vector and graph stores

| Variable | Template default | Read by | Change when | Restart? | Data impact |
|---|---|---|---|---|---|
| `QDRANT_HTTP_URL` | `http://qdrant:6333` | Python and Laravel store clients | Vector endpoint changes | Yes | Target state changes |
| `QDRANT_SCHEME`, `QDRANT_HOST`, `QDRANT_PORT` | `http`, `qdrant`, `6333` | Laravel vector configuration | Match Laravel store route | Yes | Keep aligned with HTTP URL |
| `QDRANT_API_KEY` | Empty | Store clients | Authenticated Qdrant deployment | Yes | Client credential only; does not enable server auth |
| `QDRANT_DISTANCE` | `Cosine` | Indexer/source options | Deliberate new index metric | Yes | Existing collection must be compatible |
| `NEO4J_URI` | `bolt://hawki_rag_neo4j:7687` | Python Neo4j driver | Bolt endpoint changes | Yes | Target graph changes |
| `NEO4J_HTTP_URL` | `http://hawki_rag_neo4j:7474` | Laravel graph/health clients | HTTP endpoint changes | Yes | Target graph changes |
| `NEO4J_USER`, `NEO4J_PASSWORD` | `neo4j`, `change_me` | Store clients / Neo4j adapter | Initial credentials / rotation | Yes | Persistent account change |
| `NEO4J_DATABASE` | Empty | Python driver | Explicit physical DB | Yes | Distinct from dataset namespace |
| `NEO4J_MAX_TRANSACTION_RETRY_TIME` | `30` seconds | Python driver | ADVANCED transaction retry window | Yes | No re-ingestion |

`QDRANT_COLLECTION=hawki_docs` is a default for generic clients, not the
collection selector for authorized queries. Dataset scope owns that selection.
`NEO4J_USERNAME` is a COMPATIBILITY fallback; prefer `NEO4J_USER`.
The graph driver consumes `NEO4J_URI`, not the commented `NEO4J_BOLT_URL`.

## Model providers and embeddings

| Variable | Template default | Read by | Change when | Restart? | Data impact |
|---|---|---|---|---|---|
| `RAG_DEFAULT_PROVIDER`, `GRAPH_PROVIDER` | `ollama` | Laravel defaults/settings | Change default runtime | Yes | Existing dataset scope persists |
| `OLLAMA_API_URL` | `http://hawki_ollama:11434/api` | Provider clients | Ollama endpoint changes | Yes | None |
| `OLLAMA_RAG_MODEL` | `llama3.1:8b` | Laravel settings / provider | Chat/graph default changes | Yes | New answers/extraction |
| `OLLAMA_EMBED_MODEL` | `bge-m3` | Laravel settings / provider | New embedding contract | Yes | Rebuild existing target intentionally |
| `OLLAMA_VISION_MODEL` | `qwen2.5vl:7b` | Laravel settings / provider | Vision model changes | Yes | New multimodal extraction |
| `OLLAMA_CHAT_MODELS`, `OLLAMA_EMBED_MODELS`, `OLLAMA_VISION_MODELS` | The matching models above | Laravel Settings allowlists | Offer additional models | Yes | Dataset model selected at creation |
| `CHUNK_SIZE`, `CHUNK_OVERLAP_SIZE` | `1200`, `250` | Laravel workflow payloads | Change text segmentation | Yes | Controlled reindex |
| `INGEST_BATCH_SIZE` | `64` | Laravel / indexer options | Throughput tuning | Yes | No semantic rebuild |
| `RAG_GENERATE_ANSWER` | `true` | Bridge | Enable generated answers | Yes | No index change |

The code fallback for generation is false if the variable is absent. Request
`generate=false` still disables it when the process flag is true.

[Embedding compatibility](../Core%20Concepts/Ingestion/chunking_embeddings.md)
is a dataset invariant; a new default does not migrate a populated collection.

### Optional LiteLLM

Activate with `CORE_PROFILES_BASE=litellm make up-core`, then select its aliases
through Settings. No upstream cloud key is needed for direct Ollama.

| Variable | Template default | Purpose |
|---|---|---|
| `LITELLM_API_URL` | `http://litellm:4000/v1` | Gateway endpoint |
| `LITELLM_API_KEY` | Empty | Client bearer credential if gateway requires it |
| `LITELLM_CHAT_MODEL` | `hawki-ollama-chat` | Chat/graph alias |
| `LITELLM_EMBED_MODEL` | `hawki-ollama-embedding` | Embedding alias |
| `LITELLM_VISION_MODEL` | `hawki-ollama-vision` | Vision alias |
| `LITELLM_CHAT_ALIASES`, `LITELLM_EMBED_ALIASES`, `LITELLM_VISION_ALIASES` | Lists in the template | Settings allowlists |
| `OPENAI_API_KEY`, `ANTHROPIC_API_KEY` | Empty | Upstream gateway credentials |
| `LITELLM_PORT` | `4000` | Loopback host port |

`LITELLM_OLLAMA_*`, `LITELLM_OPENAI_*`, and `LITELLM_ANTHROPIC_*` map aliases
to upstream models in [gateway config](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/docker/litellm/config.yaml).
Legacy aliases `hawki-chat`, `hawki-embedding`, and `hawki-vision` are
COMPATIBILITY options; prefer explicit provider aliases.
Cloud keys are consumed for gateway calls, but are not exclusively injected into
that container under the current shared-env wiring.

## Reranker and retrieval controls

| Variable | Template default | Read by / effect |
|---|---|---|
| `RERANKER_MODE` | `external` | Bridge default when request omits mode; code fallback `none` |
| `RERANKER_API_URL` | `http://hawki_rag_rerank/v1/rerank` | Bridge external reranker endpoint |
| `RERANKER_MIX_MODE` | `true` | Blend reranker and retrieval scores |
| `RERANKER_MIX_WEIGHT` | Code fallback `0.5` | ADVANCED retrieval share in blend |
| `RERANKER_API_KEY` | Code fallback empty | ADVANCED bearer credential for external endpoint |
| `JINA_API_KEY`, `JINA_RERANKER_MODEL` | Empty; `mixedbread-ai/mxbai-rerank-base-v1` | Jina mode uses these directly; the template model is also the local model name, so verify a valid Jina model before using that route |
| `RERANKER_PROVIDER`, `QUERY_MODE` | `cohere`, `mix` | COMPATIBILITY values with no current Python routing consumer |

The local reranker defaults to `mixedbread-ai/mxbai-rerank-base-v1`.
Its `HAWKI_RERANKER_MODEL` control is not wired through the shared dotenv in
base Compose; a service override is needed to pass it. Cohere-compatible means
API shape, not a default call to Cohere's cloud service.

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

## Graph extraction

| Variable | Template default | Change impact |
|---|---|---|
| `RAG_INGEST_GRAPH` | `false` | New source workflow option; direct text remains graph-off |
| `GRAPH_ENGINE` | `raganything` | Current extraction engine |
| `GRAPH_DOC_MAX_CHUNKS`, `GRAPH_DOC_MAX_CHARS` | `6`, `6000` | ADVANCED evidence window; rebuild graph to apply to existing facts |
| `GRAPH_RESET_CACHE_PER_DOC` | `true` | ADVANCED extraction cache lifecycle |
| `GRAPH_EMBEDDING_DIMENSIONS` | `hawki-ollama-embedding=1024,hawki-openai-embedding=1536,hawki-embedding=1024` | ADVANCED trusted dimensions for graph-only alias use |
| `RAG_WORKING_DIR` | `/app/rag_storage` | Intermediate extraction files; not the shared artifact root |

[Graph Enrichment](../Core%20Concepts/Ingestion/graph_enrichment.md) owns library
internals and graph-only repair limitations.

## External ingestion tools

| Variable | Template default | Read by |
|---|---|---|
| `CUSTOM_CRAWLER_URL`, `CUSTOM_CRAWLER_TASK_UI_URL` | `http://crawl4ai-service` | Laravel crawler/API UI integration |
| `EXTERNAL_SCRAPER_URL` | `http://crawl4ai-service` | Scraper worker and Laravel workflow configuration |
| `EXTERNAL_SCRAPER_START_PATH` | `/crawl` | Scraper |
| `EXTERNAL_SCRAPER_STATUS_PATH` | `/status/{job_id}` | Scraper |
| `EXTERNAL_SCRAPER_TOKEN` | Empty | Scraper bearer token |
| `CUSTOM_CRAWLER_API_KEY` | Empty | Laravel crawler token; worker fallback |
| `FILE_CONVERTER_BASE_URL` | `http://hawki-toolkit-file-converter-file-converter-1` | Laravel / converter worker fallback |
| `FILE_CONVERTER_URL` | Same base + `/extract` | Laravel converter configuration |
| `FILE_CONVERTER_HEALTH_URL` | Same base + `/health` | Laravel health checks |
| `EXTERNAL_CONVERTER_URL` | Same converter base | Converter worker |
| `EXTERNAL_CONVERTER_START_PATH` | `/extract` | Converter |
| `EXTERNAL_CONVERTER_STATUS_PATH` | Empty | Optional asynchronous status endpoint |
| `EXTERNAL_CONVERTER_TOKEN`, `FILE_CONVERTER_TOKEN` | `file-converter-key` | Worker / Laravel credentials; replace to match the external deployment |

Prefer explicit `EXTERNAL_*` settings for worker calls. The workers fall back to
Laravel's crawler/converter variables when their explicit values are blank.
Laravel `env()` defaults apply when absent, so blank and omitted aliases can
behave differently. Keep corresponding values aligned.

Changing URLs does not start those external services.
[Run HAWKI RAG](../Getting%20Started/2_setup.md#external-tools) owns startup.
Polling/retry budgets live in [Temporal Operations](./temporal_operations.md).

## Shared storage, secrets, and MCP

Keep `SHARED_STORAGE_ROOT`, `HAWKI_RAG_PIPELINE_ROOT`,
`HAWKI_RAG_TEMPORAL_SHARED_ROOT`, and the mounted artifact paths aligned
(default `/shared`). `PUID`/`PGID` default to 1000; shared permission adapter
values derive from them. Changing these requires coordinated permissions/mounts.

`HAWKI_RAG_WORKER_CALLBACK_SECRET` is required for activity-worker startup.
Its endpoint, age window, delivery settings, and rotation are owned by
[Temporal Operations](./temporal_operations.md#signed-worker-callbacks).

`MCP_SERVER` registers the transport path (`hawki_rag` in the template);
`MCP_BASE_URL` follows `APP_URL` in the template but does not change that route.
Retained ingestion/tool flags do not register an MCP ingestion tool:
the [server registry](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/app/Mcp/Servers/HawkiRagServer.php) is authoritative.

Web search is separate from dataset retrieval: `WEB_SEARCH_PROVIDER=tavily`
needs `TAVILY_SEARCH_API_KEY`; selecting Brave needs `BRAVE_SEARCH_API_KEY`.
Empty web-search keys do not disable local RAG.

Implementation owners: [Laravel config](https://github.com/hawk-digital-environments/HAWKI-RAG/tree/main/config),
[model settings](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/config/model_providers.php),
[bridge settings](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/services/hawki_bridge/src/hawki_bridge/settings.py),
[query tuning](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/services/hawki_bridge/src/hawki_bridge/application/query/settings.py),
[Neo4j settings](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/python_rag/packages/graph_store/src/hawki_graph_store/settings.py).
