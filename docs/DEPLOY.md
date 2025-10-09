# Production Deployment Guide

This project extends the upstream LightRAG server to store vectors in **Qdrant** and
knowledge-graph triples in **Neo4j**, while keeping the original LightRAG chunking,
summarisation, and UI workflows.

## 1. Container Build

```
docker compose -f ops/lightrag-docker-compose.yml build lightrag python_rag
```

The LightRAG container reads storage configuration from `ops/LightRAG.env`. We set:

- `LIGHTRAG_VECTOR_STORAGE=QdrantVectorDBStorage`
- `LIGHTRAG_GRAPH_STORAGE=Neo4JStorage`
- `WORKSPACE` to isolate data per deployment

## 2. Bring up the stack

```
docker compose -f ops/lightrag-docker-compose.yml up -d
```

Required services:

- `qdrant` (vector DB)
- `neo4j` (knowledge graph)
- `lightrag` (UI + API, now wired to Qdrant/Neo4j)
- `python_rag` (FastAPI bridge exposing `/ingest` and enhanced `/query`)
- `lightrag_rerank` (cohere-compatible reranker used by LightRAG)

## 3. Integration checks

Run the unit tests (mocks only):

```
PYTHONPATH=python-rag python -m unittest tests/test_qdrant_http.py tests/test_neo4j_graph.py
```

Optional end-to-end smoke test (requires running services):

```
LIGHTRAG_BASE_URL=http://localhost:8006 \
LIGHTRAG_BRIDGE_URL=http://localhost:8009 \
LIGHTRAG_SAMPLE_ROOT=/path/to/sample \
PYTHONPATH=python-rag python -m unittest tests.integration.test_ingest_and_query
```

## 4. Metrics & retries

Both adapters expose optional latency logs and simple exponential backoff. Control
them via environment variables:

| Service  | Variables                                      |
|----------|------------------------------------------------|
| Qdrant   | `QDRANT_TIMEOUT`, `QDRANT_RETRY_ATTEMPTS`, `QDRANT_LOG_LATENCY` |
| Neo4j    | `NEO4J_RETRY_ATTEMPTS`, `NEO4J_LOG_LATENCY`                     |

## 5. Upstream tracking

The adapters depend on LightRAG’s internal storage interfaces. When upgrading
LightRAG:

1. Merge upstream changes (`git pull` inside `ops/lightrag-server`).
2. Rebuild containers and re-run unit/integration tests.
3. Verify that storage APIs (`BaseVectorStorage`, `BaseGraphStorage`) have not
   changed. If they do, adjust `python-rag/qdrant_http.py` and
   `python-rag/neo4j_graph.py` accordingly.

## 6. Kubernetes/Helm (optional)

Although the repo focuses on Docker Compose, the services can be deployed on
Kubernetes. Provide the same environment variables via ConfigMaps or Secrets, and
mount persistent volumes for Qdrant and Neo4j data.

