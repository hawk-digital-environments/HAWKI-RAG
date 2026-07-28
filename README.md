# HAWKI RAG

HAWKI RAG is HAWKI's retrieval service for searchable, grounded answers over managed
content. It ships as a Docker stack with the web app, ingestion pipeline, search
databases, workflow engine, and local AI models included.

You do not need to install PHP, Python, PostgreSQL, Qdrant, Neo4j, or Ollama manually.

<img width="2720" height="992" alt="HAWKI RAG Logo green" src="https://github.com/user-attachments/assets/af606f07-185b-4204-bcb8-8db1e8a58766" />

## Requirements

- Docker with Docker Compose
- `make`
- Git
- 16 GB RAM or more recommended
- NVIDIA GPU optional; CPU mode is supported

First startup may take several minutes because Docker images and AI models are downloaded.

## Start Locally

Create the environment file:

```bash
cp .env.example .env
```

Start HAWKI RAG:

```bash
make up-core-local
```

This starts the full local experience, including the Laravel UI, RAG services,
Temporal workers, Qdrant, Neo4j, Ollama, reranker, and fresh Vite/Svelte UI
assets. It also creates the required Docker networks, creates PostgreSQL and its
persistent volume when missing, waits for PostgreSQL to become healthy, and
runs the Laravel migrations. You do not need to create or migrate the database
manually.
Use `make up-core-local` for the source-mounted development experience.
It reuses existing service images so frontend or source edits do not trigger a
full Python dependency rebuild. Run `BUILD_STACK=1 make up-core-local` after
changing Dockerfiles or locked container dependencies.

Generate the application key once:

```bash
docker compose exec hawki_rag_app php artisan key:generate
```

Check that everything is running:

```bash
make health
```

Open the app:

```text
http://localhost:8080
```

## Model Runtimes

Direct Ollama is the default model runtime and does not require LiteLLM.
HAWKI RAG connects to `llama3.1:8b` for chat and graph work, `bge-m3` for
embeddings, and `qwen2.5vl:7b` for vision. LiteLLM remains available as an
explicit optional gateway for stable Ollama, OpenAI, and Anthropic aliases.

| Capability | LiteLLM Ollama alias | OpenAI alias | Anthropic alias |
| --- | --- | --- | --- |
| Chat and graph | `hawki-ollama-chat` | `hawki-gpt-chat` | `hawki-claude-chat` |
| Embeddings | `hawki-ollama-embedding` | `hawki-openai-embedding` | Not available |
| Vision | `hawki-ollama-vision` | `hawki-gpt-vision` | `hawki-claude-vision` |

Configure the direct default in `.env`:

```env
RAG_DEFAULT_PROVIDER=ollama
GRAPH_PROVIDER=ollama
OLLAMA_API_URL=http://hawki_ollama:11434/api
OLLAMA_RAG_MODEL=llama3.1:8b
OLLAMA_EMBED_MODEL=bge-m3
OLLAMA_VISION_MODEL=qwen2.5vl:7b
```

Start the optional gateway profile before selecting `LiteLLM Gateway` in
Settings:

```bash
docker compose --profile litellm up -d litellm
```

Its aliases and optional cloud credentials remain environment-managed:

```env
LITELLM_API_URL=http://litellm:4000/v1
LITELLM_CHAT_MODEL=hawki-ollama-chat
LITELLM_EMBED_MODEL=hawki-ollama-embedding
LITELLM_VISION_MODEL=hawki-ollama-vision

# Optional cloud routes; leave blank to keep that provider unavailable.
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
```

`LITELLM_OLLAMA_*`, `LITELLM_OPENAI_*`, and `LITELLM_ANTHROPIC_*` variables in
`.env.example` map those aliases to concrete upstream models. Set
`LITELLM_API_KEY` only when the configured LiteLLM deployment itself requires
a bearer token.

The Settings page at `http://localhost:8080/settings` selects either direct
Ollama models from the `OLLAMA_*_MODELS` allowlists or LiteLLM aliases from the
`LITELLM_*_ALIASES` allowlists. Connection URLs and credentials remain
environment-managed. Provider failures are returned to the caller; the runtime
never silently switches between LiteLLM and Ollama. A dataset records its
embedding provider/model when it is created, so changing an existing dataset's
vector model requires intentional re-ingestion.

LiteLLM is not a bridge startup or core-health dependency. Its connectivity is
evaluated only when an explicitly LiteLLM-scoped operation calls the gateway.

After editing proxy variables, recreate the gateway and model consumers:

```bash
docker compose --env-file .env --profile litellm up -d --force-recreate \
  litellm hawki_rag_bridge \
  hawki-rag-temporal-workflow-worker \
  hawki-rag-temporal-ingestion-worker
```

The local proxy is bound only to `127.0.0.1`. Verify its aliases and local
Ollama routes:

```bash
curl -fsS http://127.0.0.1:4000/v1/models

curl -fsS http://127.0.0.1:4000/v1/chat/completions \
  -H 'Content-Type: application/json' \
  -d '{"model":"hawki-ollama-chat","messages":[{"role":"user","content":"Reply with OK"}]}'

curl -fsS http://127.0.0.1:4000/v1/embeddings \
  -H 'Content-Type: application/json' \
  -d '{"model":"hawki-ollama-embedding","input":["HAWKI RAG smoke test"]}'
```

## Main Pages

- Admin hub: `http://localhost:8080/admin`
- Playground: `http://localhost:8080/hawki-rag-playground`
- Upload/control page: `http://localhost:8080/pipeline-controller`
- Documents: `http://localhost:8080/documents`
- Graph explorer: `http://localhost:8080/neo4j-graph-explorer`

## Single-User Query Identity

Create one local user before opening the retrieval playground:

```bash
docker compose exec hawki_rag_app php artisan user:create
```

The browser does not need a token or sign-in step. When a credential-free query
arrives, RAWKI uses the only active local user as its internal query identity.
Removed users do not count. If there are no active users, or more than one,
query and dataset-scoped semantic-search requests fail with HTTP `503` instead
of selecting an identity arbitrarily.

By default, that user may query every current and future active dataset whose
authorization metadata and physical Qdrant collection are ready. Datasets that
are inactive, incomplete, or missing their collection are not offered in the
retrieval page. Set `HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT=false` only when
you intentionally want explicit per-dataset grants, then grant them with:

```bash
docker compose exec hawki_rag_app php artisan dataset:grant-query <dataset_id> <user_id>
```

External API and MCP clients may still use a Sanctum bearer token to select an
explicit active user. Tokens for query endpoints need the `query` ability:

```bash
docker compose exec hawki_rag_app php artisan user:token --abilities=query
```

An explicitly supplied invalid, removed-user, or insufficient-ability token is
rejected and never falls back to the implicit user.

The entire browser and HTTP API surface, including dataset-scoped retrieval,
is therefore reachable without a HAWKI-RAG credential in the intended single-user
deployment. Keep it on loopback or a trusted network, or protect it at the
reverse proxy. Several APIs can return private document evidence, delete
storage, clear graph data, or start and cancel work, so do not expose them
directly to untrusted clients.

## Add Content

Use the upload/control page when possible. For a direct import, run:

```bash
docker compose exec hawki_rag_app php artisan pipeline:start-task --source-url=https://example.edu
```

For repeated imports, add one of `daily`, `weekly`, or `monthly`:

```bash
docker compose exec hawki_rag_app php artisan pipeline:start-task --source-url=https://example.edu --refresh-cadence=daily
```

Before important imports, check the ingestion services:

```bash
docker compose exec hawki_rag_app php artisan pipeline:health
```

## Setup

Before exposing HAWKI RAG, edit `.env` and change at least:

```env
HAWKI_RAG_APP_ENV=production
HAWKI_RAG_APP_DEBUG=false
APP_URL=https://your-domain.example
DB_PASSWORD=replace_with_a_strong_password
NEO4J_PASSWORD=replace_with_a_strong_password
```

For a production-mode local startup, use:

```bash
make up-core
```

`up-core` runs the application and Python workers from their built images,
forces production Laravel/container defaults, keeps the existing host-mounted
Laravel `storage/` directory, and migrates while application writers are
stopped. It publishes the UI only on `http://localhost:8080`. Use
`up-core-local` to add the source mounts and development entrypoint from
`docker-compose.local.yml`.

For a server behind an HTTPS reverse proxy, use `up-core-server`. It does not
publish a host port and builds assets for the configured production path:

```bash
make up-core-server ENV_FILE=.env.production
```

The selected environment file is used for both Compose interpolation and the
application and worker containers.

Production checklist:

- Keep `.env` private.
- Use HTTPS through a trusted reverse proxy.
- Change all default passwords and tokens.
- Configure the scraper and file-converter service URLs in `.env`.
- Back up PostgreSQL, Qdrant, Neo4j, the shared storage volume, and Laravel's
  host-mounted `storage/` directory.
- Monitor disk usage as crawled files and embeddings grow over time.
- Run `make health` after deployments or configuration changes.

Required external services for ingestion:

```env
EXTERNAL_SCRAPER_URL=http://crawl4ai-service
EXTERNAL_CONVERTER_URL=http://hawki-toolkit-file-converter-file-converter-1
```

## Operations

View containers:

```bash
docker compose ps
```

View logs:

```bash
docker compose logs --tail=100 hawki_rag_app
```

Restart:

```bash
make restart-core
```

Stop:

```bash
make down-core
```

Inspect workflow activity from the pipeline task pages in the application. To
follow the application and Temporal worker logs:

```bash
make logs-core
```

## What Runs Inside

HAWKI RAG uses Laravel for the web app, Python FastAPI for retrieval, Temporal for
ingestion workflows, PostgreSQL for metadata, Qdrant for vector search, Neo4j for graph
search, and LiteLLM to route model calls to local Ollama or configured cloud providers.

Default models:

- Embeddings: `bge-m3`
- Answers: `llama3.1:8b`
- Image understanding: `qwen2.5vl:7b`
