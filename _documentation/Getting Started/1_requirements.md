# 1. HAWKI RAG – Requirements

## Hardware
- CPU: 8+ cores recommended; ARM (Apple Silicon) or x86_64.
- RAM: 16 GB minimum; 32 GB recommended for smoother Docker usage.
- Disk: ≥20 GB free (Docker images, volumes, Ollama models).
- GPU (optional): NVIDIA with CUDA for faster rerank/model inference; verify with `nvidia-smi`.

## Network & Ports

Docker containers communicate with each other through service names on the
internal Docker networks. A container port is not automatically reachable from
the host.

| Service | Purpose | Docker-internal endpoint |
|---|---|---|
| Laravel / Nginx | Web UI and API | `hawki_rag_app:80` |
| PostgreSQL | Laravel metadata and Temporal persistence | `postgres:5432` |
| Temporal | Workflow orchestration | `temporal:7233` |
| Qdrant | Vector database HTTP API | `qdrant:6333` |
| Neo4j HTTP | Graph database browser and HTTP API | `hawki_rag_neo4j:7474` |
| Neo4j Bolt | Graph database driver connection | `hawki_rag_neo4j:7687` |
| RAG bridge | Read-only query, graph-read, health, and Temporal-control API | `hawki_rag_bridge:80` |
| Reranker | Local reranking API | `hawki_rag_rerank:80` |
| Ollama | Local model API | `hawki_ollama:11434` |
| LiteLLM | Optional OpenAI-compatible gateway | `litellm:4000` |
| External crawler | Crawl API and task UI; started outside this Compose stack | `crawl4ai-service:80` |

The workflow, scraper, converter, and indexer workers do not listen on inbound
ports. Together with the bridge and reranker, they form the six Python
production service roles. The Laravel app initializes shared storage during its
own startup; no separate initialization or migration container is created.
Workers connect to Temporal and their owned dependencies through Docker; the
indexer performs indexing directly in-process and does not call an ingestion
endpoint on the bridge.

- `make up-core-local` publishes the UI on `http://localhost:8080`, mounts the
  source tree into the containers, and enables Laravel development mode.
- `make up-core` also publishes `http://localhost:8080`, but runs the
  production-mode images without source mounts.
- `make up-core-server` does not bind the Laravel UI to a host port. The
  separately managed reverse proxy on `hosting_network` supplies the public
  HTTP/HTTPS ports and forwards requests to `hawki_rag_app:80`.
- LiteLLM is not started by default. If its profile is enabled, its host port
  defaults to `4000` and can be changed with `LITELLM_PORT`.
- The crawler must already be running as `crawl4ai-service`. The supported
  `make up-core*` commands attach that container to `hawki-network`
  automatically so Laravel and the Temporal scraper worker can resolve it.

## Common Software (all platforms)
- Docker Engine + Compose v2 (Docker Desktop acceptable).
- `make`.
- Optional: `nvidia-container-toolkit` for GPU.

## Install `make` (quick)
- Linux (Debian/Ubuntu): `sudo apt update && sudo apt install -y make`
- Linux (RHEL/CentOS/Fedora): `sudo yum install -y make` or `sudo dnf install -y make`
- macOS: `xcode-select --install` (includes `make`) or `brew install make`
- Windows (WSL2 Ubuntu): `sudo apt update && sudo apt install -y make`

## Linux (Debian/Ubuntu/CentOS)
- Install Docker Engine + Compose plugin; add user to `docker` group.
- Install `make` (see commands above).
- For GPU: install NVIDIA driver + `nvidia-container-toolkit`; test with `nvidia-smi`.
- Compose behavior:
  - Base file is `docker-compose.yml`.
  - `make up-core` auto-enables `docker-compose-gpu-override.yml` when `nvidia-smi` is available (`USE_OLLAMA_GPU=auto`).
  - For CPU-only runs, use `USE_OLLAMA_GPU=0 make up-core`.

## macOS
- Works on Apple Silicon or Intel.
- Install Docker Desktop and ensure `make` is installed (see commands above).
- Compose behavior:
  - Makefile uses CPU mode by default (`USE_OLLAMA_GPU=0` on non-Linux hosts).
  - `ollama` uses `ollama/ollama:latest` unless GPU override is explicitly enabled.
- Start Docker Desktop before running any Make targets.

## Windows
- Use **WSL2 (Ubuntu)** for reliability; native Windows is not supported for Ollama/Make targets.
- Install: Docker Desktop with WSL2 integration, then inside WSL2 install `make` (see commands above).
- Map project into WSL2 filesystem (`/home/...`), not a mounted Windows drive, for volume performance.
- Run all commands from WSL2 shell.


## Environment files
- App/Laravel: copy `.env.example` → `.env`, fill secrets (DB, the worker
  callback HMAC, external scraper/converter, keys). Generate
  `HAWKI_RAG_WORKER_CALLBACK_SECRET` with `openssl rand -hex 32`.

## Checklist before first run
- Docker running and `docker ps` works.
- Ports listed above are unused.
- `.env` exists and is filled.
- `HAWKI_RAG_WORKER_CALLBACK_SECRET` is non-empty.
- If GPU: `nvidia-smi` returns successfully.
