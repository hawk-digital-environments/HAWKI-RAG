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

### Quick Start (local Laravel dev)
```bash
cd laravel
composer install
npm install
npm run build
php artisan serve --port=8000
# Visit http://localhost:8000
```

### Ollama models (host or container)
```bash
ollama pull llama3:8b
ollama pull bge-m3
```
*(Ollama streams best via `/generate`; `/chat` may be slower.)*


## 🔹 Example Queries

### 1. GWDG – Generating Chat (non-stream):
```bash
curl -N http://127.0.0.1:8000/api/qdrant-search \
  -H "Content-Type: application/json" \
  -d '{
    "query":"who is Vincent Timm? name his projects",
    "top_k":3,
    "provider":"gwdg"
  }'
  ```

### 2. Ollama – Generating Chat (non-stream):
```bash
curl -N http://127.0.0.1:8000/api/qdrant-search \
  -H "Content-Type: application/json" \
  -d '{
    "query":"who is Vincent Timm? name his projects",
    "top_k":3,
    "provider":"ollama",
    "is_optimized":true,
    "preferred_tags":["ausleihe"]
  }'
```
Visit the UI version at: 
http://127.0.0.1:8000/chat

RAWKI playground: http://127.0.0.1:8003/rawki-playground

### Useful Commands

- `make up-core` – start RAWKI’s core (Ollama, Qdrant, nginx, Laravel).
- `make up-rag` – build & launch the RAWKI stack (Neo4j, RAWKI core UI, reranker, bridge).
- `make ingest CRAWLED_ROOT=/path` – push a crawl into Qdrant/Neo4j via the FastAPI bridge.
- `docker compose -f ops/rawki-docker-compose.yml up -d --build` – rebuild RAWKI services only.
- `PYTHONPATH=python-rag python -m unittest tests/test_qdrant_http.py tests/test_neo4j_graph.py` – run unit tests.
- `PYTHONPATH=python-rag python -m unittest tests.integration.test_ingest_and_query` – optional integration smoke test.
- `docker exec -it hawki_ollama ollama pull bge-m3`
## RAG Documentation

- [Documentation](https://github.com/hawk-digital-environments/documentation/tree/project/RAG)

## Updated Architecture

This release extends the LightRAG paper implementation (HKU, 2024) so the core
chunking, summarisation and knowledge-graph extraction logic now runs against
external vector and graph stores while remaining fully orchestrated by RAWKI.

### High-Level Flow

1. **Laravel (PHP) ➝ FastAPI bridge** – application calls the
   `python_rag` service (`python-rag/app.py`) over HTTP. Endpoints:
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
   `LIGHTRAG_GRAPH_STORAGE` set in `ops/LightRAG.env`).

### Ingestion utilities

- `scripts/ingest_crawled.py` – sends crawled folders to the FastAPI bridge (`/ingest`)
  so Qdrant + Neo4j are populated.
- `scripts/ingest_to_lightrag.py` – replays the same folders to the LightRAG core UI’s
  `/documents/texts` endpoint so the UI/GraphML caches stay in sync.

Use the bridge script whenever downstream services depend on the `/query`
endpoint. Run both scripts if the RAWKI UI must mirror the latest corpus.

```bash
python3 scripts/ingest_crawled.py \
  --root storage/app/private/crawled-data/hawk-sample-20 \
  --base-url http://localhost:8004 \
  --provider ollama \
  --graph \
  --collection embeddings_hawk \
  --distance Cosine \
  --chunk-chars 3200 \
  --chunk-overlap 50 \
  --batch 8 \
  --timeout 600 \
  --summary-file public/ingest_summary.json
```

```bash
python3 scripts/ingest_to_lightrag.py \
  --root storage/app/private/crawled-data/hawk-full \
  --base-url http://localhost:8006 \
  --batch 8 \
  --timeout 180
```

### Inspecting counts (Qdrant & Neo4j)

Every successful ingest writes a JSON summary to `public/ingest_summary.json`, e.g.

```json
{
  "timestamp": "2025-09-26T15:42:11Z",
  "ingested_points": 295,
  "documents": {
    "total_docs": 22,
    "processed_docs": 22,
    "skipped_docs": 0,
    "by_format": {
      "markdown": 18,
      "txt": 4
    },
    "doc_ids": ["doc-001", "doc-002", "..."]
  },
  "qdrant": {
    "primary_collection": "embeddings_hawk",
    "primary_point_count": 302,
    "auxiliary_collections": {
      "hawki_entities": 301,
      "hawki_relationships": 301
    }
  },
  "neo4j": {
    "entity_count": 2071,
    "triplet_count": 4189,
    "relationship_counts": [...],
    "label_counts": [...]
  },
  "summary_file": "public/ingest_summary.json"
}
```

*(`doc_ids` list truncated above for readability.)*

Need a quick spot-check? Use curl and Cypher from your host:

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
PYTHONPATH=python-rag python -m unittest tests/test_qdrant_http.py tests/test_neo4j_graph.py

LIGHTRAG_BASE_URL=http://localhost:8006 \
LIGHTRAG_BRIDGE_URL=http://localhost:8004 \
LIGHTRAG_SAMPLE_ROOT=/absolute/path/to/sample \
PYTHONPATH=python-rag python -m unittest tests.integration.test_ingest_and_query
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

1. **Build images** – `docker compose -f ops/rawki-docker-compose.yml build`.
2. **Launch** – `docker compose -f ops/rawki-docker-compose.yml up -d`.
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
| `rawki_rerank`      | Cohere-compatible reranker (BAAI/bge-reranker-v2-m3 by default)         |
| `hawki_ollama`      | Local embeddings provider (Ollama)                                      |
| `hawki-vector-database-app` | Laravel PHP application                                         |

### RAWKI Foundations (built on LightRAG)

The pipeline adheres to the LightRAG paper’s workflow: documents are chunked,
summarised, entities/relations are extracted, and the graph relationships influence
retrieval quality. The main difference is that vectors are persisted in Qdrant and the
knowledge graph in Neo4j without changing the core LightRAG logic.
