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
search, and Ollama for local AI models.

Default models:

- Embeddings: `bge-m3`
- Answers: `llama3.1:8b`
- Image understanding: `qwen2.5vl:7b`
