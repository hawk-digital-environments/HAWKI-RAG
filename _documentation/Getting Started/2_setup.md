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
- `ENV_FILE` (default `.env`): choose the file used both for Compose
  interpolation and as the service environment file.
- `HAWKI_RAG_APP_ENV` / `HAWKI_RAG_APP_DEBUG`: production-mode Laravel
  defaults used by `make up-core`; `make up-core-local` explicitly uses
  `local` / `true`.
- `COMPOSE_PROFILES`: optional profile toggle (for example `litellm` for the model gateway or `gpu` for `raganything_api_gpu`).
- `BASE_COMPOSE_FILE` / `GPU_OVERRIDE_COMPOSE`: advanced override of compose filenames.

Examples:
```bash
# Force CPU mode
USE_OLLAMA_GPU=0 make up-core

# Force GPU override
USE_OLLAMA_GPU=1 make up-core

# Start with profile-gated GPU API too
USE_OLLAMA_GPU=1 COMPOSE_PROFILES=gpu make up-core

# Start the optional LiteLLM gateway too
CORE_PROFILES_BASE=litellm make up-core
```

## Compose/Dockerfile roles
- `docker-compose.yml`:
  - CPU-safe base stack with direct local `ollama`; the OpenAI-compatible `litellm` gateway is profile-gated.
- `docker-compose-gpu-override.yml`:
  - Overrides only `ollama` to CUDA build + NVIDIA device reservation.
- `docker/laravel.Dockerfile`: builds `hawki_rag_app`.
- `Dockerfile`: builds `hawki_rag_bridge` (`python-rag` target) and `hawki_rag_rerank` (`rerank` target).
- `docker/qdrant.Dockerfile`: extends `qdrant/qdrant` and installs `curl` for health checks.

## Automatic networks and database setup

The supported `make up-core`, `make up-core-local`, and `make up-core-server`
commands create the required Docker networks automatically. They also start
PostgreSQL, wait until it is healthy, and run the Laravel migrations before
starting services that can write data. No separate PostgreSQL installation,
database creation, or manual `php artisan migrate` command is required.

If you need to create only the shared networks, or intend to run raw
`docker compose` commands instead of the supported Make targets, run:

```bash
make network   # creates shared docker networks hawki-network + hosting_network
```

This command is safe to rerun. The error
`network hosting_network declared as external, but could not be found` means
Compose did not reach PostgreSQL or Laravel; create the missing network and
retry the original command.

## Start stack
```bash
make up-core
```

What `make up-core` does:

| Step | What happens |
| --- | --- |
| Compose context | Uses computed `COMPOSE_FILE` with `ENV_FILE` and optional `COMPOSE_PROFILES`. |
| Safe upgrade | Builds images, stops application writers, migrates with a one-off app container, then starts the new application and workers. |
| Model readiness | Pulls Ollama models: `bge-m3`, `llama3.1:8b`, `llama3.2:1b`, `qwen2.5vl:7b`. |
| Gateway readiness | Starts LiteLLM only when the `litellm` profile is enabled and exposes aliases on `http://127.0.0.1:4000/v1`. |
| Runtime mode | Uses baked application sources with production Laravel defaults and publishes the UI on `http://localhost:8080`. |

For an HTTPS reverse-proxy deployment without a published host port, use
`make up-core-server ENV_FILE=.env.production`.

For source mounts, development defaults, and live UI publishing, use:

```bash
make up-core-local
```

Development startup reuses existing service images. After changing a
Dockerfile or locked container dependency, rebuild explicitly:

```bash
BUILD_STACK=1 make up-core-local
```

## Model pulls (Ollama)
- Default pulls: `bge-m3`, `llama3.1:8b`, `llama3.2:1b`, `qwen2.5vl:7b`.
- Optional (manual): `llama3.2:3b`
  - `docker exec hawki_ollama ollama pull llama3.2:3b`
- Rough VRAM guide: `bge-m3` < 4 GB, `llama3.2:1b` ~2 GB, `llama3.1:8b` prefers 12-16 GB, `qwen2.5vl:7b` prefers 8-12 GB.

HAWKI calls these local models directly by default. When the optional LiteLLM
profile is enabled and selected in Settings, the same models are available as
`hawki-ollama-chat`, `hawki-ollama-embedding`, and `hawki-ollama-vision` aliases.
OpenAI and Anthropic aliases require their provider keys in `.env`.

```bash
CORE_PROFILES_BASE=litellm make up-core
curl -fsS http://127.0.0.1:4000/v1/models
```

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
- If pulls are slow: pre-pull the direct runtime images with `make pull-core` or check VPN/proxy.
- If Ollama pulls hang: pull manually in `hawki_ollama`.
- If GPU is expected but not detected on Linux: install `nvidia-container-toolkit` and restart Docker, or force CPU mode with `USE_OLLAMA_GPU=0`.
