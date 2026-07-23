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

- Experience hub: `http://localhost:8080/hawki-rag`
- Admin hub: `http://localhost:8080/admin`
- Playground: `http://localhost:8080/hawki-rag-playground`
- Upload/control page: `http://localhost:8080/pipeline-controller`
- Documents: `http://localhost:8080/documents`
- Graph explorer: `http://localhost:8080/neo4j-graph-explorer`

## Authenticate Playground Queries

Production and shared environments require an authenticated browser principal.
For a standalone browser session, create a real user, grant that user access to
the requested dataset, and issue a Sanctum token:

```bash
docker compose exec hawki_rag_app php artisan user:create
docker compose exec hawki_rag_app php artisan dataset:grant-query <dataset_id> <user_id>
docker compose exec hawki_rag_app php artisan user:token --abilities=query
```

The create command prints the `user_id`. Open the playground and paste the
token into **Dataset authentication**. RAWKI sends it once to `/auth/session`,
establishes an HttpOnly Laravel session, clears the input, and then lists only
datasets granted to that principal. The token needs the explicit `query`
ability. The token command defaults to `query` and never creates wildcard
tokens.

Browser access to the admin UI requires a persisted admin role. Promote only
trusted users, then issue admin API credentials with the explicit `admin`
ability when bearer access is needed:

```bash
docker compose exec hawki_rag_app php artisan user:role <user_id> admin
docker compose exec hawki_rag_app php artisan user:token --abilities=admin
```

During migration, existing `operator` bearer abilities can be accepted by
setting `HAWKI_RAG_ADMIN_AUTH_ACCEPT_LEGACY_OPERATOR_ABILITY=true`. The switch
defaults to false; disable it again after replacing those credentials.
Wildcard tokens do not count as explicit admin credentials and must be
reissued. Existing browser users are not upgraded implicitly; they remain
non-admin until assigned the role above.

Local development may skip the token prompt without weakening production. Set
`HAWKI_RAG_QUERY_AUTH_BYPASS=true`, restrict its environments to
`local,testing`, and set `HAWKI_RAG_QUERY_AUTH_BYPASS_USER_ID` to a persisted
active user that already has explicit `dataset_grants`. The browser attaches
that principal only to `/query` and `/query/datasets`; `/api/*` remains
Sanctum-protected. RAWKI hard-denies this bypass outside `local` and `testing`.

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
