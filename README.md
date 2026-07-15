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
make up-core
```

This starts the full local experience, including the Laravel UI, RAG services,
Temporal workers, Temporal UI/devtools, Qdrant, Neo4j, Ollama, reranker, and
fresh Vite/Svelte UI assets.
Use `make up-core` for the local full-stack experience.

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

Temporal UI:

```text
http://localhost:8081
```

## LiteLLM Model Gateway

LiteLLM is the default and only model-provider boundary used by HAWKI RAG.
Laravel, the Python bridge, and the ingestion workers select stable LiteLLM
aliases; only the proxy knows whether an alias resolves to local Ollama,
OpenAI, or Anthropic. The `litellm` service is part of the default Compose
stack, so `make up-core` starts it without an optional profile.

| Capability | Local Ollama alias | OpenAI alias | Anthropic alias |
| --- | --- | --- | --- |
| Chat and graph | `hawki-ollama-chat` | `hawki-gpt-chat` | `hawki-claude-chat` |
| Embeddings | `hawki-ollama-embedding` | `hawki-openai-embedding` | Not available |
| Vision | `hawki-ollama-vision` | `hawki-gpt-vision` | `hawki-claude-vision` |

The local aliases are the defaults and require no provider key. Legacy local
aliases (`hawki-chat`, `hawki-embedding`, and `hawki-vision`) remain available
for compatibility. Configure upstream targets and credentials in `.env`:

```env
RAG_DEFAULT_PROVIDER=litellm
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

The Settings page at `http://localhost:8080/settings` selects only aliases from
the configured `LITELLM_*_ALIASES` allowlists. Connection URLs and provider
credentials remain environment-managed: the UI shows variable names and
configured/not-configured status, but never receives secret values.

After editing proxy variables, recreate the gateway and model consumers:

```bash
docker compose --env-file .env up -d --force-recreate \
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

- Experience hub: `http://localhost:8080/hawki-rag`
- Admin hub: `http://localhost:8080/admin`
- Playground: `http://localhost:8080/hawki-rag-playground`
- Upload/control page: `http://localhost:8080/pipeline-controller`
- Documents: `http://localhost:8080/documents`
- Graph explorer: `http://localhost:8080/neo4j-graph-explorer`

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
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
DB_PASSWORD=replace_with_a_strong_password
NEO4J_PASSWORD=replace_with_a_strong_password
```

For server startup, use:

```bash
make up-core-server
```

Production checklist:

- Keep `.env` private.
- Use HTTPS through a trusted reverse proxy.
- Change all default passwords and tokens.
- Configure the scraper and file-converter service URLs in `.env`.
- Back up PostgreSQL, Qdrant, Neo4j, and the shared storage volume.
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

Optional workflow diagnostics:

```bash
make up-core
```

Then open `http://localhost:8081`.

## What Runs Inside

HAWKI RAG uses Laravel for the web app, Python FastAPI for retrieval, Temporal for
ingestion workflows, PostgreSQL for metadata, Qdrant for vector search, Neo4j for graph
search, and LiteLLM to route model calls to local Ollama or configured cloud providers.

Default models:

- Embeddings: `bge-m3`
- Answers: `llama3.1:8b`
- Image understanding: `qwen2.5vl:7b`
