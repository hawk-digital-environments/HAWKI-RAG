# HAWKI RAG – HAWKI’s Retrieval Stack

HAWKI RAG is the customised retrieval deployment used in the HAWKI project. It keeps the
Laravel application and FastAPI bridge you already know, but rebrands the end-user
experience and Docker stack, highlighting the combo of **Qdrant** + **Neo4j** + the
HAWKI RAG pipeline.

HAWKI RAG is designed for fast retrieval over crawled HAWKI content. By default it uses
`bge-m3` for embeddings and `llama3:8b` / `llama3.1:8b` for grounded answers.

## Chapter 0 — At a Glance

- **What you get:** Laravel UI, a FastAPI bridge, Qdrant vectors, Neo4j graph,
  a reranker, Ollama for models, plus a crawler microservice.
- **Why it exists:** accelerate retrieval over crawled HAWKI content while keeping
  the pipeline modular and persistence externalized.
- **Where config lives:** `docker-compose.yml`, `python_rag/LightRAG.env`, and `Makefile`.

## Chapter 1 — Quick Start (Docker)

```bash
docker network create hawki-network || true
docker compose up -d
```

Laravel app: `http://localhost:8080`  
MCP endpoint: `http://localhost:8080/mcp/hawki_rag`  
Python RAG API: `http://localhost:8003`  
FastAPI bridge (ingest/query): `http://localhost:8009`  
Crawler API: `http://localhost:8004`  
Ollama: `http://localhost:11434`

### Quick Start (local Laravel dev)

```bash
composer install
npm install
npm run build
```

### Ollama models (host or container)

```bash
ollama pull llama3:8b
ollama pull llama3.1:8b
ollama pull bge-m3
```

HAWKI RAG playground (Laravel UI): `http://127.0.0.1:8080/hawki-rag-playground`

## Chapter 2 — Architecture in One Page

### High-Level Flow

1. **Laravel (PHP) ➝ FastAPI bridge**: the app calls `hawki_rag_bridge`
   (`python_rag/app/main.py`) over HTTP.
   - `POST /ingest` – embeds content, stores vectors in Qdrant and triplets in Neo4j.
   - `POST /query` – retrieves from Qdrant, enriches with Neo4j, reranks, and returns
     combined context + answer.
2. **Qdrant** – collections `hawki_chunks`, `hawki_entities`, and
   `hawki_relationships` are created automatically and hold chunk vectors plus metadata.
3. **Neo4j** – persists `Entity` nodes and `REL` edges for every triplet.
4. **Reranker** – a Cohere-compatible reranker service (`hawki_rag_rerank`) improves final
   ordering of retrieved chunks.

### Networking Model

All services share a single external Docker network: **`hawki-network`**.
This makes every container resolvable by its compose service name.

## Chapter 3 — Services, Ports, and Rules

The rules below are derived from `docker-compose.yml` and the runtime environment.

### Core Storage

**`hawki_qdrant` (Vector DB)**
- **Image/build:** `docker/qdrant.Dockerfile` → `hawki-qdrant:local`.
- **Ports:** `6333` (REST), `6334` (gRPC).
- **Volume:** `qdrant_data:/qdrant/storage`.
- **Healthcheck:** `GET http://localhost:6333/readyz`.
- **Role:** persists chunk vectors and structured metadata.

**`hawki_rag_neo4j` (Graph DB)**
- **Image:** `neo4j:5.22`.
- **Ports:** `7475:7474` (browser), `7688:7687` (bolt).
- **Volume:** `neo4j_data:/data`.
- **Healthcheck:** `wget --spider http://localhost:7474/browser`.
- **Role:** persists entities and relationships extracted from content.

### Ingest and Query Plane

**`hawki_rag_bridge` (FastAPI bridge)**
- **Image/build:** `hawki-rag-python:local`.
- **Port:** `8009:8000`.
- **Env:** `python_rag/LightRAG.env` plus service overrides.
- **Role:** `/ingest` and `/query` API for applications and scripts.
- **Notes:** runs Uvicorn with `--reload` for dev-style iteration.

**`raganything_api` (RAG Anything API)**
- **Image/build:** `hawki-rag-python:local`.
- **Port:** `8003:8003`.
- **Profiles:** GPU by default via `make`; CPU fallback is automatic if no GPU is detected.
- **Role:** FastAPI wrapper used by the Laravel UI and integrations.

**`hawki_rag_rerank` (Reranker)**
- **Image/build:** `Dockerfile` target `rerank`.
- **Port:** `8008:8000`.
- **Healthcheck:** `curl -fsS http://localhost:8000/health`.
- **Role:** Cohere-compatible reranker (default model: `mixedbread-ai/mxbai-rerank-base-v1`).

### Application Layer

**`hawki_rag_app` (Laravel app)**
- **Image/build:** `Dockerfile` target `laravel-app`.
- **Port:** exposed through nginx (see below).
- **Volumes:** project source mounted into `/var/www`.
- **Depends on:** `hawki_rag_rabbitmq`, `crawl4ai-service`.

**`hawki_rag_nginx` (Gateway)**
- **Image:** `nginx:1.27-alpine`.
- **Port:** `8080:80`.
- **Config:** `docker/nginx.conf` bound to `/etc/nginx/conf.d/default.conf`.
- **Role:** routes web requests to Laravel and public endpoints.

### Communication + Models

**`hawki_rag_rabbitmq` (Queue + Management UI)**
- **Image:** `rabbitmq:3.13-management-alpine`.
- **Ports:** `5672` (AMQP), `15672` (management UI).
- **Volumes:** `rabbitmq_data`, `rabbitmq_logs`.
- **Healthcheck:** `rabbitmq-diagnostics -q ping`.

**`hawki_ollama` (Local LLM + embeddings)**
- **Image/build:** `docker/ollama.Dockerfile` → `hawki-ollama:local`.
- **Port:** `11434:11434`.
- **Volume:** `ollama:/root/.ollama`.
- **Profiles:** GPU by default via `make`; CPU fallback is automatic if no GPU is detected.
- **Healthcheck:** `curl -fsS http://localhost:11434/api/tags`.

### Crawling & Shared Storage

**`crawl4ai-service` (Crawler microservice)**
- **Image:** `crawl4ai-fastapi:latest`.
- **Port:** `8004:8000`.
- **Volumes:**
  - `./data:/app/data` (new job data)
  - `./output:/app/output` (legacy output)
  - `shared_storage:/app/shared` (shared Laravel storage)
- **Env:** RabbitMQ integration enabled; storage path via `DATA_PATH`.
- **Healthcheck:** `curl -f http://localhost:8000/health`.

## Chapter 4 — Data Persistence Map

- **Qdrant**: `qdrant_data` volume (vectors & collections).
- **Neo4j**: `neo4j_data` volume (graph DB files).
- **Ollama**: `ollama` volume (models, caches).
- **RabbitMQ**: `rabbitmq_data` and `rabbitmq_logs`.
- **RAG working data**: `rag_storage` volume for local cache/state.
- **Crawler output**: `./data` and `shared_storage` (host + shared volume).

## Chapter 5 — Ingestion Utilities

- `python_rag/ingest/ingest_crawled.py` – sends crawled folders to the FastAPI bridge (`/ingest`)
  so Qdrant + Neo4j are populated.

Use the bridge script whenever downstream services depend on `/query`.

```bash
python3 python_rag/ingest/ingest_crawled.py \
  --root storage/app/private/crawled-data/sample-hawk \
  --base-url http://localhost:8009 \
  --provider ollama \
  --graph \
  --collection embeddings_hawk \
  --distance Cosine \
  --chunk-chars 3200 \
  --chunk-overlap 100 \
  --batch 8 \
  --timeout 1800 \
  --summary-file public/ingest_summary.json
```

```bash
## Chapter 6 — Inspecting Counts (Qdrant & Neo4j)

Every successful ingest writes a JSON summary to `public/ingest_summary.json`.

```bash
# Qdrant (primary collection)
curl -s -X POST http://localhost:6333/collections/embeddings_hawk/points/count \
     -H 'Content-Type: application/json' -d '{"exact": true}'

# Qdrant auxiliary collections (graph data)
for col in hawki_entities hawki_relationships; do
  curl -s -X POST "http://localhost:6333/collections/${col}/points/count" \
       -H 'Content-Type: application/json' -d '{"exact": true}'
done

# Neo4j
docker exec -it hawki_rag_neo4j cypher-shell -u "${NEO4J_USER:-neo4j}" -p "${NEO4J_PASSWORD:-YOURPASS}" \
  "MATCH (n:Entity) RETURN count(n) AS entity_count"
docker exec -it hawki_rag_neo4j cypher-shell -u "${NEO4J_USER:-neo4j}" -p "${NEO4J_PASSWORD:-YOURPASS}" \
  "MATCH (:Entity)-[r:REL]->(:Entity) RETURN count(r) AS triplet_count"
```

## Chapter 7 — Makefile Shortcuts

- `make up-core` – start core services (Qdrant, nginx, Ollama, Laravel).
- `make up-rag` – build & launch the HAWKI RAG stack (Neo4j, reranker, bridge).
- `make ingest CRAWLED_ROOT=/path` – push a crawl into Qdrant/Neo4j.
- `make test-services` – curl Qdrant/Neo4j/bridge/rerank health endpoints.
- `make logs-rag`, `make down-rag` – convenience wrappers for compose.

## Chapter 8 — Testing

- **Unit tests** (`tests/test_qdrant_http.py`, `tests/test_neo4j_graph.py`) mock the
  HTTP/driver layers to exercise retry/backoff paths.
- **Integration smoke test** (`tests/integration/test_ingest_and_query.py`) ingests a
  sample doc and performs a retrieval against the running stack.

Run them with:

```bash
PYTHONPATH=python_rag python -m unittest tests/test_qdrant_http.py tests/test_neo4j_graph.py

LIGHTRAG_BRIDGE_URL=http://localhost:8004 \
PYTHONPATH=python_rag python -m unittest tests.integration.test_ingest_and_query
```

## Chapter 9 — Deployment Cheat Sheet

Full deployment notes live in `docs/DEPLOY.md`. Highlights:

1. **Build images** – `docker compose -f docker-compose.yml build`.
2. **Launch** – `docker compose -f docker-compose.yml up -d`.
3. **Verify** – `make test-services` and optional integration test.
4. **Upgrades** – when upstream dependencies change, rebuild, rerun tests,
   and verify storage interfaces still match our adapters.

## Chapter 10 — Scrape and Convert Commands

```bash
php artisan crawl:and-convert "https://www.hawk.de/" \
    --max-pages=100000 \
    --output-dir=storage/app/private/crawled-data/hawk-full \
    --label="hawk-full" \
    --image-exceptions="data:image,.svg,icon,favicon,logo,sprite,placeholder" \
    --date="meta[property='og:updated_time']"
```

```bash
php artisan crawl:and-convert "https://www.hawk.de/" \
    --max-pages=100000 \
    --output-dir=storage/app/private/crawled-data/hawk-text \
    --label="hawk-text" \
    --skip-images \
    --date="meta[property='og:updated_time']"
```

### Re-ingesting failed docs

```bash
python3 python_rag/ingest/retry_ingest_docs.py \
  --root storage/app/private/crawled-data/hawk-text \
  --collection embeddings_hawk \
  --base-url http://localhost:8009 \
  --doc-ids-file /home/ixdlab-admin/Hawki/HAWKI_RAG/storage/app/private/failed_doc_ids.txt \
  --batch 16
```

## Further Reading

- Step-by-step replication guide: `docs/story.md`
