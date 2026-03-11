# Operating HAWKI RAG with Makefile

## Platform defaults from `Makefile`
- Base compose is always `docker-compose.yml`.
- Makefile exports `COMPOSE_FILE` before calling `docker compose`.
- Linux:
  - `USE_OLLAMA_GPU=auto` (default) enables `docker-compose-gpu-override.yml` when `nvidia-smi` exists.
  - Effective `COMPOSE_FILE`: `docker-compose.yml:docker-compose-gpu-override.yml`.
- macOS/non-Linux:
  - CPU mode by default (`USE_OLLAMA_GPU=0`).
  - Effective `COMPOSE_FILE`: `docker-compose.yml`.
- Ollama container name is unified: `hawki_ollama`.

## Key overrides (per run)
- `USE_OLLAMA_GPU`:
  - `auto` (default): detect GPU on Linux.
  - `1`: force GPU override.
  - `0`: force CPU mode.
- `ENV_FILE` (default `.env`): choose env file.
- `CRAWLED_ROOT` (default `/app/shared`): ingest root for `make ingest`.
- `COMPOSE_PROFILES`: optional profile toggle (e.g. `gpu` to include `raganything_api_gpu`).
- `BASE_COMPOSE_FILE` / `GPU_OVERRIDE_COMPOSE`: advanced override of compose filenames.

Examples:
```bash
# Force CPU mode
USE_OLLAMA_GPU=0 make up-core

# Force GPU override
USE_OLLAMA_GPU=1 make up-core

# Start with profile-gated GPU API too
USE_OLLAMA_GPU=1 COMPOSE_PROFILES=gpu make up-core
```

## Compose/Dockerfile roles
- `docker-compose.yml`:
  - CPU-safe base stack and the default `ollama` service.
- `docker-compose-gpu-override.yml`:
  - Overrides only `ollama` to CUDA build + NVIDIA device reservation.
- `docker/laravel.Dockerfile`: builds `hawki_rag_app`.
- `Dockerfile`: builds `hawki_rag_bridge` (`python-rag` target) and `hawki_rag_rerank` (`rerank` target).
- `docker/qdrant.Dockerfile`: extends `qdrant/qdrant` and installs `curl` for health checks.

## One-time networks
```bash
make network   # creates shared docker networks hawki-network + hosting_network
```
Run this once per machine (or after pruning Docker networks). Safe to rerun.

## Start stack
```bash
make up-core
```
- Uses computed `COMPOSE_FILE`, plus `ENV_FILE` and optional `COMPOSE_PROFILES`.
- Prints selected compose files before launch.
- Pulls Ollama models: `bge-m3`, `llama3.1:8b`, `llama3.2:1b`.

## Model pulls (Ollama)
- Default pulls: `bge-m3`, `llama3.1:8b`, `llama3.2:1b`.
- Optional (manual): `llama3.2:3b`
  - `docker exec hawki_ollama ollama pull llama3.2:3b`
- Rough VRAM guide: `bge-m3` <4 GB, `llama3.2:1b` ~2 GB, `llama3.1:8b` prefers 12-16 GB.

## Health and logs
```bash
make test-services     # curl checks for Qdrant, Neo4j, bridge, reranker
make logs-core         # follow compose logs
```

## Ingest content (inside containers, internal URLs)
```bash
docker exec hawki_rag_bridge sh -lc "python /app/ingest/ingest_crawled.py \
  --root /app/shared/<folder> \
  --base-url http://localhost:8000 \
  --provider ollama --graph --batch 16"
```
- Host path: `storage/app/public/<folder>` -> bridge path: `/app/shared/<folder>`.
- Services must be running (`make up-core`).

## Shut down / reset
```bash
make down-core
make down-rag
make neo4j-fresh   # stops Neo4j, wipes /data, restarts clean graph
```

## Troubleshooting tips for Make targets
- If pulls are slow: pre-pull with `docker compose pull` or check VPN/proxy.
- If Ollama pulls hang: pull manually in `hawki_ollama`.
- If GPU is expected but not detected on Linux: install `nvidia-container-toolkit` and restart Docker, or force CPU mode with `USE_OLLAMA_GPU=0`.
