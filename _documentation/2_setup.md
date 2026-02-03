# Operating HAWKI RAG with Make

## Key variables (override per run)
- `OPS_COMPOSE` (default `docker-compose.yml`)
- `ENV_FILE` (default `python_rag/LightRAG.env`)
- `INGEST_BASE` (default `http://localhost:8009`)
- `RERANK_BASE` (default `http://localhost:8008`)
- GPU auto-detect: `COMPOSE_PROFILES=gpu` when `nvidia-smi` is present; otherwise `cpu`.

## One-time network
```bash
make network   # creates shared docker network hawki-network
```

## Start stacks
- Core (Qdrant, nginx, Ollama, Laravel app):
```bash
make up-core
```
  - Auto-pulls Ollama models `bge-m3`, `llama3:8b`, `llama3.1:8b`.
- Full RAG (adds reranker, FastAPI bridge, RAG API):
```bash
make up-rag
```

## Health & logs
```bash
make test-services     # curl checks for Qdrant, Neo4j, bridge, reranker
make logs-core         # follow core services
make logs-rag          # follow RAG services
```

## Ingest content (minimal)
```bash
make ingest CRAWLED_ROOT=/abs/path/to/crawled-data
```
- Sends files to FastAPI bridge → Qdrant + Neo4j. Requires stack running.

## Laravel pipeline helper
```bash
make pipeline PIPELINE_URL=https://example.com \
  PIPELINE_COLLECTION=embeddings_hawk PIPELINE_GRAPH=true PIPELINE_PROVIDER=ollama
```
- Runs `php artisan hawki_rag:pipeline` inside app container; optional flags: `PIPELINE_LABEL`, `PIPELINE_OUTPUT_DIR`, `PIPELINE_DISTANCE`, `PIPELINE_BATCH`, `PIPELINE_CHUNK_CHARS`, `PIPELINE_TIMEOUT`.

## Shut down / reset
```bash
make down-core
make down-rag
make neo4j-fresh   # stops Neo4j, wipes /data, restarts clean graph
```

## Troubleshooting tips for Make targets
- If pulls are slow: pre-pull with `docker compose pull` or ensure VPN/proxy set.
- If Ollama model pulls hang: run `docker exec hawki_ollama ollama pull <model>` manually.
- If GPU not detected but present: ensure `nvidia-container-toolkit` installed and Docker daemon restarted.
