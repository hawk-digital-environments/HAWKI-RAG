# RAWKI – HAWKI’s Retrieval Stack

RAWKI is the customised LightRAG deployment used in the HAWKI project. It keeps the
Laravel application and FastAPI bridge you already know, but rebrands the end-user
experience and Docker stack, highlighting the combo of **Qdrant** + **Neo4j** + the
LightRAG core pipeline.

## Welcome to RAWKI

RAWKI is designed for fast retrieval over crawled HAWKI content. By default it uses
`bge-m3` for embeddings and `llama3:8b` for grounded answers. Make sure the following
requirements are satisfied before starting the stack:

- ≥ 6 GB RAM free (8 GB recommended) so Ollama can serve `llama3:8b`
- ≥ 4 CPU cores for smooth ingest and query workloads
- ≥ 15 GB disk space for Qdrant, Neo4j, and model caches

### Quick Start (Docker)
```bash
docker compose up -d
```

Laravel app: http://localhost:8080  
MCP endpoint: http://localhost:8080/mcp/rawki  
Python RAG API: http://localhost:8003  
Ollama: http://localhost:11434

### Quick Start (local Laravel dev)
```bash
composer install
npm install
npm run build
```

### Ollama models (host or container)
```bash
ollama pull llama3:8b
ollama pull bge-m3
```

RAWKI playground (Laravel UI): http://127.0.0.1:8002/rawki-playground  
LightRAG playground (core UI): http://127.0.0.1:8006/

### Useful Commands

- `make up-core` – start RAWKI’s core (Ollama, Qdrant, nginx, Laravel).
- `make up-rag` – build & launch the RAWKI stack (Neo4j, RAWKI core UI, reranker, bridge).
- `make ingest CRAWLED_ROOT=/path` – push a crawl into Qdrant/Neo4j via the FastAPI bridge.
- `docker compose -f docker-compose.yml up -d --build` – rebuild RAWKI services only.
- `PYTHONPATH=python_rag python -m unittest tests/test_qdrant_http.py tests/test_neo4j_graph.py` – run unit tests.
- `PYTHONPATH=python_rag python -m unittest tests.integration.test_ingest_and_query` – optional integration smoke test.

## Updated Architecture

This release extends the LightRAG paper implementation (HKU, 2024) so the core
chunking, summarisation and knowledge-graph extraction logic now runs against
external vector and graph stores while remaining fully orchestrated by RAWKI.

### High-Level Flow

1. **Laravel (PHP) ➝ FastAPI bridge** – application calls the
   `python_rag` service (`python_rag/app/main.py`) over HTTP. Endpoints:
   - `POST /ingest` – embeds content, stores vectors in Qdrant and triplets in Neo4j.
   - `POST /query` – retrieves from Qdrant, enriches with Neo4j relationships, applies
     reranking, and returns combined context + answer.
2. **Qdrant** – collections `hawki_chunks`, `hawki_entities`, and
   `hawki_relationships` are created automatically and hold chunk vectors plus
   metadata for traceability back to the original PDF/page.
3. **Neo4j** – persists `Entity` nodes and `REL` edges for every triplet produced by
   RAWKI’s extractors, giving a navigable knowledge graph.
4. **RAWKI core server** – still provides the UI and background workers, but now reads
   from Qdrant/Neo4j via the official storage adapters (`LIGHTRAG_VECTOR_STORAGE` and
   `LIGHTRAG_GRAPH_STORAGE` set in `python_rag/LightRAG.env`).

### Ingestion utilities

- `python_rag/ingest/ingest_crawled.py` – sends crawled folders to the FastAPI bridge (`/ingest`)
  so Qdrant + Neo4j are populated.
- `python_rag/ingest/ingest_to_lightrag.py` – replays the same folders to the LightRAG core UI’s
  `/documents/texts` endpoint so the UI/GraphML caches stay in sync.

Use the bridge script whenever downstream services depend on the `/query`
endpoint. Run both scripts if the RAWKI UI must mirror the latest corpus.

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

If the same collection/root pair was ingested earlier, the CLI now prompts whether
to resume (skip previously embedded docs) or start over.
```

```bash
python3 python_rag/ingest/ingest_to_lightrag.py \
  --root storage/app/private/crawled-data/hawk-full \
  --base-url http://localhost:8006 \
  --batch 8 \
  --timeout 180
```

### Inspecting counts (Qdrant & Neo4j)

Every successful ingest writes a JSON summary to `public/ingest_summary.json`.

```bash
# Qdrant (primary collection)
curl -s -X POST http://localhost:6333/collections/embeddings_hawk/points/count \
     -H 'Content-Type: application/json' -d '{"exact": true}'

# Qdrant auxiliary collections (LightRAG/RAWKI graph data)
for col in hawki_entities hawki_relationships; do
  curl -s -X POST "http://localhost:6333/collections/${col}/points/count" \
       -H 'Content-Type: application/json' -d '{"exact": true}'
done

# Neo4j
docker exec -it rawki_neo4j cypher-shell -u "${NEO4J_USER:-neo4j}" -p "${NEO4J_PASSWORD:-YOURPASS}" \
  "MATCH (n:Entity) RETURN count(n) AS entity_count"
docker exec -it rawki_neo4j cypher-shell -u "${NEO4J_USER:-neo4j}" -p "${NEO4J_PASSWORD:-YOURPASS}" \
  "MATCH (:Entity)-[r:REL]->(:Entity) RETURN count(r) AS triplet_count"
```

### Testing

- **Unit tests** (`tests/test_qdrant_http.py`, `tests/test_neo4j_graph.py`) mock the
  HTTP/driver layers to exercise retry/backoff paths.
- **Integration smoke test** (`tests/integration/test_ingest_and_query.py`) ingests a
  sample doc and performs a retrieval against the running stack.

Run them with:

```bash
PYTHONPATH=python_rag python -m unittest tests/test_qdrant_http.py tests/test_neo4j_graph.py

LIGHTRAG_BASE_URL=http://localhost:8006 \
LIGHTRAG_BRIDGE_URL=http://localhost:8004 \
LIGHTRAG_SAMPLE_ROOT=/absolute/path/to/sample \
PYTHONPATH=python_rag python -m unittest tests.integration.test_ingest_and_query
```

### Makefile & Smoke Tests

The project-level `Makefile` automates common tasks:

- `make up-core` – builds/starts Qdrant, Neo4j, Ollama, nginx, Laravel app.
- `make up-rag` – builds/starts LightRAG, the FastAPI bridge, and reranker.
- `make ingest CRAWLED_ROOT=/path` – pushes a crawl into Qdrant/Neo4j.
- `make test-services` – curls all five services (Qdrant, Neo4j, LightRAG UI,
  python_rag bridge, reranker) to confirm readiness.
- `make logs-rag`, `make down-rag`, etc., wrap the compose commands.

### Deployment Cheat Sheet

Full deployment notes live in [`docs/DEPLOY.md`](docs/DEPLOY.md). Highlights:

1. **Build images** – `docker compose -f docker-compose.yml build`.
2. **Launch** – `docker compose -f docker-compose.yml up -d`.
3. **Verify** – `make test-services` and optional integration test (see above).
4. **Upgrades** – when LightRAG releases upstream changes, merge, rebuild, rerun the
   tests, and check that the storage interfaces (`BaseVectorStorage`,
   `BaseGraphStorage`) still match our adapters.

### Components in Docker

| Service             | Role                                                                    |
|---------------------|-------------------------------------------------------------------------|
| `hawki_qdrant`      | Vector store (collections for chunks/entities/relationships)            |
| `rawki_neo4j`       | Knowledge graph database (stores RAWKI triplets)                       |
| `rawki_core`        | RAWKI UI/API using Qdrant + Neo4j adapters                              |
| `rawki_bridge`      | FastAPI bridge exposing `/ingest` + enhanced `/query` endpoints         |
| `rawki_rerank`      | Cohere-compatible reranker (mixedbread-ai/mxbai-rerank-base-v1 by default) |
| `hawki_ollama`      | Local embeddings provider (Ollama)                                      |
| `hawki-vector-database-app` | Laravel PHP application                                         |


### Scrape and Convert Command 
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
### RE-ingesting failed Docs Command 

```bash
python3 python_rag/ingest/retry_ingest_docs.py \
  --root storage/app/private/crawled-data/hawk-text \
  --collection embeddings_hawk \
  --base-url http://localhost:8009 \
  --doc-ids-file /home/ixdlab-admin/Rawki/RAWKI/storage/app/private/failed_doc_ids.txt \
  --batch 16
```

### RAWKI (built on LightRAG)

The pipeline adheres to the LightRAG paper’s workflow: documents are chunked,
summarised, entities/relations are extracted, and the graph relationships influence
retrieval quality. The main difference is that vectors are persisted in Qdrant and the
knowledge graph in Neo4j without changing the core LightRAG logic.

## Further Reading

- Step-by-step replication guide: [docs/story.md](docs/story.md)
