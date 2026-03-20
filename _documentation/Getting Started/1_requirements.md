# HAWKI RAG – Requirements

## Hardware
- CPU: 8+ cores recommended; ARM (Apple Silicon) or x86_64.
- RAM: 16 GB minimum; 32 GB recommended for smoother Docker usage.
- Disk: ≥20 GB free (Docker images, volumes, Ollama models).
- GPU (optional): NVIDIA with CUDA for faster rerank/model inference; verify with `nvidia-smi`.

## Network & Ports
- Ensure these host ports are free: 3306 (MariaDB) and `ADMINER_PORT` for Adminer (default is 8005 in this repo).
- RAG services (bridge, reranker, RAG API, Qdrant, Neo4j, Ollama) stay on the internal Docker network by default and do not bind host ports.

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
- App/Laravel: copy `.env.example` → `.env`, fill secrets (DB, queues, keys).

## Checklist before first run
- Docker running and `docker ps` works.
- Ports listed above are unused.
- `.env` exists and is filled.
- If GPU: `nvidia-smi` returns successfully.
