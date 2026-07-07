# HAWKI RAG

HAWKI RAG is HAWKI's retrieval service for searchable, grounded answers over managed
content. It ships as a Docker stack with the API app, ingestion pipeline, search
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

This starts the local API and pipeline stack, including Laravel, the retrieval
bridge, Temporal workers, Temporal UI/devtools, Qdrant, Neo4j, Ollama, and the reranker.

Generate the application key once:

```bash
docker compose exec hawki_rag_app php artisan key:generate
```

Check that everything is running:

```bash
make health
```

Open the API host:

```text
http://localhost:8080
```

Temporal UI:

```text
http://localhost:8081
```

Swagger UI:

```text
http://localhost:8080/swagger/index.html
```

## Add Content

Use the canonical V2 endpoints from Swagger or your application client. Before important imports, check the ingestion services:

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
- Configure the file-converter service URLs in `.env`.
- Back up PostgreSQL, Qdrant, Neo4j, and the shared storage volume.
- Monitor disk usage as uploaded files, converted markdown, and embeddings grow over time.
- Run `make health` after deployments or configuration changes.

Required external services for ingestion:

```env
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
