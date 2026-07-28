# 4. Installation

## Prerequisites
- **Docker + Docker Compose v2**: runs every service in containers.
- **Git**: downloads the project.
- **Make**: runs scripted commands easily.
- **curl**: tests HTTP endpoints.

## Step 1 - Create env file
- Command: `cp .env.example .env`

## Step 2 - Set secrets (do this once)
Open `.env` in an editor and set:

| Variable | Required | When to set |
| --- | --- | --- |
| `APP_KEY` | Yes | Always |
| `DB_PASSWORD` | Yes | Always |
| `NEO4J_PASSWORD` | Yes | Always |
| `EXTERNAL_SCRAPER_URL` | Yes | When starting source ingestion workflows |
| `EXTERNAL_CONVERTER_URL` | Yes | When starting source ingestion workflows |
| `FILE_CONVERTER_TOKEN` | Optional | If you use file conversion |
| `TAVILY_SEARCH_API_KEY` | Optional | If `WEB_SEARCH_PROVIDER=tavily` |
| `BRAVE_SEARCH_API_KEY` | Optional | If `WEB_SEARCH_PROVIDER=brave` |
| `JINA_API_KEY` | Optional | Only if reranker mode is `jina` |
| `OPENAI_API_KEY` | Optional | Enables the GPT chat/vision and OpenAI embedding aliases in LiteLLM |
| `ANTHROPIC_API_KEY` | Optional | Enables the Claude chat/vision aliases in LiteLLM |

## Step 3 - Docker networks

No separate command is required when you use one of the `make up-core*`
commands in Step 4. The startup command creates the external `hawki-network`
and `hosting_network` networks automatically.

:::tip "Manual network recovery"
    If you run `docker compose` directly, or Docker networks were pruned, run `make network` first. It is safe to rerun and prints whether each network was created or already existed.
:::

## Step 4 - Start services
- Production-mode command with UI at `http://localhost:8080`: `make up-core`
- Reverse-proxy production command without a host port: `make up-core-server`
- Source-mounted development command: `make up-core-local`

:::tip "Database setup is automatic"
    The startup command creates the PostgreSQL container and persistent volume, waits for PostgreSQL to become healthy, and runs all Laravel migrations before writable services start. You do not need to create the database or run `php artisan migrate` yourself.
:::

:::warning "Missing external network"
    `network hosting_network declared as external, but could not be found` is a Docker network error, not a database migration error. Run `make network`, then repeat the startup command. Current `make up-core*`, `make migrate-core`, and `make restart-core` commands perform this check automatically.
:::

:::tip "Verification"
    `docker ps` should show containers such as `hawki_rag_app`, `hawki_qdrant`, `hawki_rag_bridge`, `hawki_rag_rerank`, `hawki_rag_neo4j`, `hawki_ollama`, `hawki_rag_postgres`, and `temporal`. `hawki_litellm` appears only when the `litellm` profile is enabled.
:::

## Step 5 - Health check everything
- Commands: `make health` and `make test-services`
- You should be able to see `OK` for all components.

Optionally start LiteLLM and confirm that its aliases loaded:

```bash
CORE_PROFILES_BASE=litellm make up-core
curl -fsS http://127.0.0.1:4000/v1/models
```

## Step 6 - Connect HAWKI-RAG to HAWKI (MCP Tool)
Plug HAWKI-RAG into HAWKI as an MCP tool by following the [official HAWKI AI Models & Tools guide](https://docs.hawki.info/architecture/10.2-AI%20Models%20and%20Tools).
