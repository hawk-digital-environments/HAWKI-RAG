# Operating HAWKI RAG with Makefile

## Key variables (override per run)
- `OPS_COMPOSE` (default `docker-compose.yml`)
- `ENV_FILE` (default `python_rag/LightRAG.env`)
- `INGEST_BASE` (default `http://localhost:8009`)
- `RERANK_BASE` (default `http://localhost:8008`)
- GPU auto-detect: `COMPOSE_PROFILES=gpu` when `nvidia-smi` is present; otherwise `cpu`.
> Use these knobs without editing files: `OPS_COMPOSE` picks the docker compose file, `ENV_FILE` supplies the Python stack env, `INGEST_BASE`/`RERANK_BASE` are the URLs Make targets call, and `COMPOSE_PROFILES` flips between GPU/CPU automatically (GPU if `nvidia-smi` works).
> Prereqs: ensure Docker/Compose v2 are installed and running; install Node.js + npm (for Laravel/Vite assets) and Composer (PHP deps). If building locally (not just via containers), run `composer install` and `npm install` first.

## One-time network
```bash
make network   # creates shared docker network hawki-network
```
> Run this once per machine (or after pruning Docker networks) to create the shared `hawki-network` so containers resolve each other by service name. Safe to rerun; it no-ops if the network already exists.

## Start stacks
- Core (Qdrant, nginx, Ollama, Laravel app):
```bash
make up-core
```
  - Auto-pulls Ollama models `bge-m3`, `llama3.1:8b`.
  - Use this to bring up the UI plus vector store/model host; it’s the minimal stack for browsing and basic testing.
- Full RAG (adds reranker, FastAPI bridge, RAG API):
```bash
make up-rag
```
  - Adds the reranker and FastAPI bridge so you can ingest and query end-to-end; run after (or instead of) `make up-core` when you need full retrieval. Auto-pulls `llama3.2:1b` for fast graph/summarization steps.

## Model pulls (Ollama)
- Default pulls: `bge-m3` (embeddings), `llama3.1:8b` (chat/answers), `llama3.2:1b` (fast graph/summarization).
- Optional (not auto-pulled): `llama3.2:3b` for higher-quality graph extraction; pull manually if needed: `docker exec hawki_ollama_gpu ollama pull llama3.2:3b`.
- Rough VRAM guide: `bge-m3` <4 GB, `llama3.2:1b` ~2 GB, `llama3.1:8b` prefers 12–16 GB. On CPU expect higher latency.

Use these to verify services and inspect runtime output when something feels off:
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
Full workaround (step-by-step):
1) Prepare data: choose a crawl/output folder containing text/HTML/PDFs (e.g., `storage/app/private/crawled-data/mycrawl`), and note the absolute path.
2) Start services: run `make up-rag` (or `make up-core` + `make up-rag`) so bridge, reranker, Qdrant, Neo4j, and Ollama are up. Check with `make test-services`.
3) Run ingest: `make ingest CRAWLED_ROOT=/abs/path/to/crawled-data` (defaults: provider `ollama`, graph on, batch 16). If you need custom collection or chunking, run the Python script directly with flags (see ingest-and-data page).
4) Verify: check `public/ingest_summary.json` for results; count vectors via Qdrant curl (Chapter 6) and graph nodes via Neo4j cypher; watch logs with `make logs-rag`.
Common adjustments: increase `MCP_INGEST_BATCH`/`--batch` for speed, raise `--timeout` for large corpora, disable `--graph` if you only need vectors.

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
