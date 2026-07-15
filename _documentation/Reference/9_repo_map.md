# 9. Repository Map
## Root
| File/Path | Description |
|---|---|
| `Makefile` | Helper targets (`network`, `up-core`, `health`, `ingest`, logs/restart helpers) using docker compose and container exec. |
| `docker-compose.yml` | Base Compose (CPU-safe direct `ollama` runtime plus the optional profile-gated `litellm` gateway). |
| `docker-compose-gpu-override.yml` | Optional NVIDIA override for `ollama` (used when GPU mode is enabled). |
| `Dockerfile` | Multi-stage build for Python services: `python-rag` (bridge/API image) and `rerank` (local reranker). |
| `docker/` | Dockerfiles and runtime assets, including `docker/litellm/config.yaml`, which maps public HAWKI aliases to Ollama, OpenAI, and Anthropic targets. |
| `.env.example` | Template for runtime configuration. |

## Laravel (PHP) side
| Path | Description |
|---|---|
| `app/Http/Controllers/API/HawkiRagProxyController.php` | Proxies user queries to bridge. |
| `app/Http/Controllers/API/IngestController.php` | Starts/stops ingest processes, validates paths, writes status. |
| `app/Http/Controllers/API/IngestStatusController.php` | Reads/updates ingest status and logs. |
| `app/Http/Controllers/Health/` | Health and monitoring HTTP controllers, separated from core UI/API controllers. |
| `app/Services/GraphService/Neo4jAdmin.php` | Clears all nodes/edges in Neo4j. |
| `config/config.php` | App config mapping for endpoint URLs, model defaults, and ingest/log paths (keys listed below). |
| `routes/web_ui.php` | Browser-facing UI pages and UI-consumed RAG endpoints. |
| `routes/internal_api.php` | Token-authenticated internal/API-client endpoints mounted under `/api`. |
| `routes/health.php` | Separate health and monitoring route sector for RAG monitor, ping, and pipeline health surfaces. |
| `storage/` | Logs (`storage/logs`) and shared files (`storage/app/public`, bound to shared volume). |

### Config keys (`config/config.php` + `.env`)
| Variable | Purpose |
|---|---|
| `APP_NAME` | Laravel app display name. |
| `APP_URL` | Base URL used by Laravel for generated links. |
| `APP_KEY` | Laravel encryption/session secret. |
| `DB_HOST` | PostgreSQL host. |
| `DB_PORT` | PostgreSQL port. |
| `DB_DATABASE` | PostgreSQL database name for Laravel metadata. |
| `DB_USERNAME` | PostgreSQL username. |
| `DB_PASSWORD` | PostgreSQL password. |
| `NEO4J_HTTP_URL` | Neo4j HTTP endpoint used by graph operations. |
| `NEO4J_USER` | Neo4j username. |
| `NEO4J_PASSWORD` | Neo4j password. |
| `QDRANT_HTTP_URL` | Qdrant endpoint used by vector indexing/search. |
| `HAWKI_RAG_BRIDGE_URL` | HAWKI-RAG bridge base URL used for query, ingest, health, and graph cache operations. |
| `HAWKI_RAG_TEMPORAL_SHARED_ROOT` | Shared Temporal ingestion handoff root path inside containers. |
| `TEMPORAL_ADDRESS` | Temporal frontend address used by Laravel and workers. |
| `RAG_DEFAULT_PROVIDER` | Runtime provider; the default is direct `ollama`, while `litellm` is an explicit optional selection. |
| `OLLAMA_API_URL` / `OLLAMA_*_MODEL` | Direct Ollama endpoint and default chat, embedding, and vision models. |
| `OLLAMA_*_MODELS` | Comma-separated direct model allowlists accepted by Laravel Settings. |
| `LITELLM_API_URL` | OpenAI-compatible gateway endpoint used only when the optional LiteLLM provider is selected. |
| `LITELLM_API_KEY` | Optional bearer token when the selected gateway requires proxy authentication. |
| `LITELLM_CHAT_MODEL` | Default allowlisted chat/graph alias. |
| `LITELLM_EMBED_MODEL` | Default embedding alias captured for newly created datasets. |
| `LITELLM_VISION_MODEL` | Default allowlisted vision alias. |
| `LITELLM_*_ALIASES` | Comma-separated alias allowlists accepted by Laravel Settings. |
| `LITELLM_OLLAMA_*` | Local Ollama endpoint and concrete model targets owned by LiteLLM. |
| `LITELLM_OPENAI_*` / `OPENAI_API_KEY` | OpenAI target models and proxy-only credential. |
| `LITELLM_ANTHROPIC_*` / `ANTHROPIC_API_KEY` | Anthropic target models and proxy-only credential. |

## Python side
| Path | Description |
|---|---|
| `python_rag/` | Python RAG stack containing the FastAPI bridge, RAG API components, and reranker code. |
| `python_rag/app/` | FastAPI entrypoints and route modules (`main.py`, `query.py`, `ingest.py`). |
| `python_rag/temporal_rag/` | Temporal workflows, activities, adapters, and worker entrypoints for source ingestion. |
| `python_rag/pipeline/` | Query and ingest pipeline logic/helpers. |
| `python_rag/infrastructure/rerank/` | Local reranker adapter service. |
| `python_rag/requirements.txt` | Python dependency manifest. |
| `rag_storage/` | Local repo directory; in Compose, the RAG API working directory is the named volume `rag_storage` mounted at `/app/rag_storage` for `raganything_api_gpu` (GPU profile). `hawki_rag_bridge` uses `./python_rag:/app` and `shared_storage:/app/shared`. |

## Assets and build
| Path | Description |
|---|---|
| `resources/` | Standard Laravel frontend resources (views/assets). |
| `public/` | Laravel public web root served by the web stack/reverse proxy. |

## Volumes (from compose)
| Volume | Description |
|---|---|
| `rawki_shared_storage` | Shared volume between Laravel and bridge (`/app/shared`). |
| `qdrant_data`, `neo4j_data`, `hawki_postgres_data`, `rag_storage`, `ollama` | Persistent data volumes for vector DB, graph DB, PostgreSQL/Temporal SQL data, RAG working data, and model store. |

## Logs & statuses
| Item | Location/Command |
|---|---|
| Laravel logs | `storage/logs/laravel.log` |
| Temporal worker logs | `docker compose logs -f hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker` |
| Bridge/RAG API runtime logs | `docker logs <container>` |
