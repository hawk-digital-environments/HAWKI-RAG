# HAWKI RAG – Requirements

## Hardware
- CPU: 8+ cores recommended; ARM (Apple Silicon) or x86_64.
- RAM: 16 GB minimum; 32 GB recommended for smoother Docker usage.
- Disk: ≥20 GB free (Docker images, volumes, Ollama models).
- GPU (optional): NVIDIA with CUDA for faster rerank/model inference; verify with `nvidia-smi`.

## Network & Ports
- Ensure these host ports are free: 8080 (nginx/UI), 8003 (RAG API), 8004 (crawler), 8008 (reranker), 8009 (bridge), 11434 (Ollama), 6333/6334 (Qdrant), 7475/7688 (Neo4j).
- Stable broadband for pulling Docker images and Ollama models.

## Common Software (all platforms)
- Docker Engine + Compose v2 (Docker Desktop acceptable).
- `make`, `curl`, `python3`.
- Optional: `jq` for pretty JSON; `nvidia-container-toolkit` for GPU.

## Linux (Debian/Ubuntu/CentOS)
- Install Docker Engine + Compose plugin; add user to `docker` group.
- `sudo apt install make curl python3 jq` (or `yum/dnf` equivalents).
- For GPU: install NVIDIA driver + `nvidia-container-toolkit`; test with `nvidia-smi`.

## macOS
- Works on Apple Silicon or Intel.
- Install: `brew install docker docker-compose make coreutils jq`.
- Docker Desktop: enable Rosetta for x86 images if using Intel-based images on ARM.
- Start Docker Desktop before running any Make targets.

## Windows
- Use **WSL2 (Ubuntu)** for reliability; native Windows is not supported for Ollama/Make targets.
- Install: Docker Desktop with WSL2 integration, then inside WSL2 `sudo apt install make curl python3 jq`.
- Map project into WSL2 filesystem (`/home/...`), not a mounted Windows drive, for volume performance.
- Run all commands from WSL2 shell.


## Environment files
- App/Laravel: copy `.env.example` → `.env`, fill secrets (DB, queues, keys).
- Python RAG: `python_rag/LightRAG.env` (model settings, provider URLs).
- Optional overrides: (deprecated) `Makefile.local` — avoid using; prefer Docker/Make targets above.

## Checklist before first run
- Docker running and `docker ps` works.
- Ports listed above are unused.
- `.env` and `python_rag/LightRAG.env` exist and are filled.
- If GPU: `nvidia-smi` returns successfully.
