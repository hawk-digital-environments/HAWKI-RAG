# Operating HAWKI RAG with Makefile

## Platform defaults from `Makefile`
Base compose is always `docker-compose.yml`, and `Makefile` exports `COMPOSE_FILE` before calling `docker compose`.

| Linux | macOS |
| --- | --- |
| Default `USE_OLLAMA_GPU` is `auto`. | Default `USE_OLLAMA_GPU` is `0`. |
| If `nvidia-smi` exists, `docker-compose-gpu-override.yml` is added automatically. | Runs in CPU mode by default (no GPU override). |
| Effective `COMPOSE_FILE`: `docker-compose.yml:docker-compose-gpu-override.yml` (when GPU is detected). | Effective `COMPOSE_FILE`: `docker-compose.yml`. |

## Key overrides (per run)
- `USE_OLLAMA_GPU`:
  - `auto` (default): detect GPU on Linux.
  - `1`: force GPU override.
  - `0`: force CPU mode.
- `ENV_FILE` (default `.env`): choose env file.
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

What `make up-core` does:

| Step | What happens |
| --- | --- |
| Compose context | Uses computed `COMPOSE_FILE` with `ENV_FILE` and optional `COMPOSE_PROFILES`. |
| Launch preview | Prints selected compose files before startup. |
| Model readiness | Pulls Ollama models: `bge-m3`, `llama3.1:8b`, `llama3.2:1b`, `qwen2.5vl:7b`. |
| UI readiness | Builds Vite/Svelte assets and publishes them into the running Laravel app. |

## Model pulls (Ollama)
- Default pulls: `bge-m3`, `llama3.1:8b`, `llama3.2:1b`, `qwen2.5vl:7b`.
- Optional (manual): `llama3.2:3b`
  - `docker exec hawki_ollama ollama pull llama3.2:3b`
- Rough VRAM guide: `bge-m3` < 4 GB, `llama3.2:1b` ~2 GB, `llama3.1:8b` prefers 12-16 GB, `qwen2.5vl:7b` prefers 8-12 GB.

## Health and logs
```bash
make test-services     # curl checks for Qdrant, Neo4j, bridge, reranker
make logs-core         # follow compose logs
```

## Repo hygiene / CI-ready artifacts
Keep bytecode, cache, and test artifacts out of git by using:

```bash
make clean
```

This clears `__pycache__`, `*.py[cod]`, `*.log`, `.pytest_cache`, `.ruff_cache`, `.mypy_cache`, `.coverage*`, `.tox`, `.venv`, `dist`, `build`.

## Start source ingestion
```bash
docker exec -it hawki_rag_app php artisan pipeline:start-task \
  --source-url=https://example.edu \
  --refresh-cadence=daily
```

Laravel starts `IngestSourceWorkflow`; Temporal then coordinates scraper, converter, and ingestion workers.

### Shared volume path mapping
Path mapping: `rawki_shared_storage` (Docker volume) -> `/app/shared` (bridge and Laravel app).

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
