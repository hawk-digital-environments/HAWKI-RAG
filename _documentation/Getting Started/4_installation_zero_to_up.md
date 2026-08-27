# 4. Installation

## Prerequisites
- **Docker + Docker Compose v2**: runs every service in containers.
- **Git**: downloads the project.
- **Make**: runs scripted commands easily.
- **curl**: tests HTTP endpoints.
- **OpenSSL**: generates local application secrets; it is preinstalled on most Linux and macOS systems.

## Step 1 - Create the environment file

From the repository root, create your local configuration:

```bash
cp .env.example .env
```

`.env.example` contains working defaults for the provided Docker stack. You do
not need to fill every empty variable or replace every service URL. Your
personal `.env` can contain passwords and API keys, so never commit it to Git.

## Step 2 - Configure `.env`

### Change these values before the first start

Four values must be created for a normal local installation:

| Variable | What it controls | What you should do |
|---|---|---|
| `APP_KEY` | Laravel encryption for sessions and protected application data | Generate it once and keep it private and stable. |
| `DB_PASSWORD` | Authentication for the PostgreSQL database | Replace `change_me` with a unique password before PostgreSQL is initialized. |
| `NEO4J_PASSWORD` | Authentication for the Neo4j graph database | Replace `change_me` with a different unique password before Neo4j is initialized. |
| `HAWKI_RAG_WORKER_CALLBACK_SECRET` | HMAC signing between Python activity workers and Laravel | Generate one random secret and keep the same value for Laravel and all workers. |

Generate a 32-byte Laravel key:

```bash
openssl rand -base64 32
```

Copy the output after the `base64:` prefix:

```env
APP_KEY=base64:PASTE_THE_GENERATED_VALUE_HERE
```

Generate each database password separately:

```bash
openssl rand -hex 24
```

Generate the worker callback secret separately:

```bash
openssl rand -hex 32
```

Your edited values should have this shape:

```env
APP_KEY=base64:YOUR_GENERATED_APP_KEY
DB_PASSWORD=YOUR_FIRST_GENERATED_PASSWORD
NEO4J_PASSWORD=YOUR_SECOND_GENERATED_PASSWORD
HAWKI_RAG_WORKER_CALLBACK_SECRET=YOUR_GENERATED_32_BYTE_HEX_SECRET
```

:::warning Keep persistent credentials stable

Set these values before the first `make up-core`. Changing a password in
`.env` later does not automatically change the matching user inside an existing
PostgreSQL or Neo4j volume. Changing `APP_KEY` can also invalidate encrypted
sessions and stored encrypted values.

:::

### Understand Docker service addresses

The values below are addresses on Docker networks. Keep their defaults when you
use the provided Compose stack:

| Variable | Default | Used for |
|---|---|---|
| `DB_HOST` | `postgres` | Laravel's PostgreSQL connection |
| `TEMPORAL_ADDRESS` | `temporal:7233` | Workflow orchestration |
| `HAWKI_RAG_BRIDGE_URL` | `http://hawki_rag_bridge` | Read-only retrieval and Temporal-control API |
| `QDRANT_HTTP_URL` | `http://qdrant:6333` | Vector storage and search |
| `NEO4J_HTTP_URL` | `http://hawki_rag_neo4j:7474` | Graph storage |
| `OLLAMA_API_URL` | `http://hawki_ollama:11434/api` | Local embeddings and language models |

### Configure source ingestion

Website and file ingestion uses a crawler and converter that run outside the
core HAWKI-RAG Compose stack. The standard internal addresses are already in
`.env.example`:

```env
CUSTOM_CRAWLER_URL=http://crawl4ai-service
CUSTOM_CRAWLER_TASK_UI_URL=http://crawl4ai-service
EXTERNAL_SCRAPER_URL=http://crawl4ai-service

FILE_CONVERTER_BASE_URL=http://hawki-toolkit-file-converter-file-converter-1
EXTERNAL_CONVERTER_URL=http://hawki-toolkit-file-converter-file-converter-1
```

Keep these values when the external containers use the expected names. Change
them only when your crawler or converter has a different Docker service name.
Do not add host-only ports such as `localhost:8000`.

:::warning Setting a URL does not start the service

The crawler and converter must already be running. A supported
`make up-core*` command connects the containers to `hawki-network` when it can
find them, but it does not install or start them. HAWKI-RAG can perform queries
without these services, but new website and file ingestion will be unavailable.

:::

Use authentication tokens only when the corresponding external service
requires them:

| Preferred variable | Fallback variable | Purpose |
|---|---|---|
| `EXTERNAL_SCRAPER_TOKEN` | `CUSTOM_CRAWLER_API_KEY` | Token sent to the crawler |
| `EXTERNAL_CONVERTER_TOKEN` | `FILE_CONVERTER_TOKEN` | Token sent to the file converter |

The configured token must match the external service. For production, replace
sample values such as `file-converter-key` in both systems.

### Optional providers

The default installation uses local Ollama models and the local reranker. No
OpenAI, Anthropic, Jina, Tavily, or Brave key is required for local document
ingestion and retrieval.

| Feature | Configuration | What an empty key means |
|---|---|---|
| Tavily web search | `WEB_SEARCH_PROVIDER=tavily` and `TAVILY_SEARCH_API_KEY` | Tavily-backed web search is unavailable; local RAG still works. |
| Brave web search | `WEB_SEARCH_PROVIDER=brave` and `BRAVE_SEARCH_API_KEY` | Brave-backed web search is unavailable; local RAG still works. |
| Jina reranking | `RERANKER_MODE=jina` and `JINA_API_KEY` | Jina cannot be used. The default `external` mode uses the local reranker and needs no Jina key. |
| OpenAI through LiteLLM | `OPENAI_API_KEY` | OpenAI aliases remain unavailable. |
| Anthropic through LiteLLM | `ANTHROPIC_API_KEY` | Claude aliases remain unavailable. |

`RERANKER_PROVIDER=cohere` describes the local reranker's compatible API
format; it does not mean that the default installation calls Cohere's cloud
service.

:::tip LiteLLM keys are optional

OpenAI and Anthropic keys are read only when the optional LiteLLM profile is
running and one of their aliases is selected.

:::

### Local URL versus server URL

For normal local usage, keep:

```env
APP_URL=http://localhost:8080
SESSION_SECURE_COOKIE=false
```

For a real HTTPS deployment, set `APP_URL` to the public HAWKI-RAG address and
set `SESSION_SECURE_COOKIE=true`. `MCP_BASE_URL` follows `APP_URL` by default.

Laravel's runtime mode comes directly from the selected dotenv file. Set
`APP_ENV=production` and `APP_DEBUG=false` for a production deployment.

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
    The startup command creates PostgreSQL and its persistent volume, starts the Laravel app, and runs all Laravel migrations inside that app container before writable services start. You do not need to create the database or run `php artisan migrate` yourself, and no separate migration container is left behind.
:::

## Step 5 - Health check everything
- Command: `make health`
- You should be able to see `OK` for all components.

Optionally start LiteLLM and confirm that its aliases loaded:

```bash
CORE_PROFILES_BASE=litellm make up-core
curl -fsS http://127.0.0.1:4000/v1/models
```

## Step 6 - Connect HAWKI-RAG to HAWKI (MCP Tool)
Plug HAWKI-RAG into HAWKI as an MCP tool by following the [official HAWKI AI Models & Tools guide](https://docs.hawki.info/architecture/10.2-AI%20Models%20and%20Tools).
