# Operating HAWKI RAG with Makefile

## Key variables (override per run)
- `OPS_COMPOSE` (default `docker-compose.yml`)
- `ENV_FILE` (default `.env`)
- `CRAWLED_ROOT` (default `/app/shared`)
- GPU auto-detect: `COMPOSE_PROFILES=gpu` when `nvidia-smi` is present; otherwise `cpu`.
> Use these knobs without editing files: `OPS_COMPOSE` picks the docker compose file, `ENV_FILE` supplies env vars, `CRAWLED_ROOT` sets ingest root for `make ingest`, and `COMPOSE_PROFILES` flips between GPU/CPU automatically (GPU if `nvidia-smi` works).
> Prereqs: ensure Docker/Compose v2 are installed and running; install Node.js + npm (for Laravel/Vite assets) and Composer (PHP deps). If building locally (not just via containers), run `composer install` and `npm install` first.

## One-time networks
```bash
make network   # creates shared docker networks hawki-network + hosting_network
```
> Run this once per machine (or after pruning Docker networks). Safe to rerun; it no-ops if the networks already exist.

## Start stack
```bash
make up-core
```
- Starts the full compose stack using `OPS_COMPOSE` + `ENV_FILE` with the auto-detected `COMPOSE_PROFILES` (`gpu` or `cpu`).
- Auto-pulls Ollama models `bge-m3`, `llama3.1:8b`, `llama3.2:1b`.
- Connects the file-converter container to `hawki-network` when present.

## Model pulls (Ollama)
- Default pulls: `bge-m3` (embeddings), `llama3.1:8b` (chat/answers), `llama3.2:1b` (fast graph/summarization).
- Optional (not auto-pulled): `llama3.2:3b` for higher-quality graph extraction; pull manually if needed: `docker exec hawki_ollama_gpu ollama pull llama3.2:3b`.
- Rough VRAM guide: `bge-m3` <4 GB, `llama3.2:1b` ~2 GB, `llama3.1:8b` prefers 12–16 GB. On CPU expect higher latency.

Use these to verify services and inspect runtime output when something feels off:
## Health & logs
```bash
make test-services     # curl checks for Qdrant, Neo4j, bridge, reranker
make logs-core         # follow core services
```

## Ingest content (inside containers, internal URLs)
```bash
docker exec hawki_rag_bridge sh -lc "python /app/ingest/ingest_crawled.py \
  --root /app/shared/<folder> \
  --base-url http://localhost:8000 \
  --provider ollama --graph --batch 16"
```
- Place your files on host in `storage/app/public/<folder>`; inside bridge they appear at `/app/shared/<folder>`.
- Services must be running (`make up-core`).
- Verify: check `storage/logs/ingest_status.json` and `storage/logs/ingest_progress_cache.log` inside app container if needed (`docker exec hawki_rag_app cat ...`).

## Shut down / reset
```bash
make down-core
make down-rag
make neo4j-fresh   # stops Neo4j, wipes /data, restarts clean graph
```

## Troubleshooting tips for Make targets
- If pulls are slow: pre-pull with `docker compose pull` or ensure VPN/proxy set.
- If Ollama model pulls hang: run `docker exec hawki_ollama_gpu ollama pull <model>` (or `hawki_ollama_cpu`) manually.
- If GPU not detected but present: ensure `nvidia-container-toolkit` installed and Docker daemon restarted.
