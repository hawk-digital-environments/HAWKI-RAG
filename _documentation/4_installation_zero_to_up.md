# 4. Installation: Zero to Running

Audience: never used terminal, Laravel, or RAG. Follow in order.

## Prerequisites (Why you need them)
- **Docker + Docker Compose v2**: runs every service in containers.
- **Git**: downloads the project.
- **Make**: runs scripted commands easily.
- **curl**: tests HTTP endpoints.

## Step 1 - Install prerequisites
- Mac: install Docker Desktop, `brew install git make curl`.
- Linux: install Docker Engine + Compose plugin, `sudo apt install git make curl`.
- Windows: install Docker Desktop + WSL2; do all commands inside WSL2 Ubuntu.

## Step 2 - Get the code
- Command: `git clone <repo-url> && cd RAWKI`
- What it does: downloads project; enters folder.
- Success: `ls` shows files like `Makefile`, `docker-compose.yml`, `docker-compose-gpu-override.yml`.
- Failure: "permission denied" -> fix folder permissions; "git: command not found" -> install git.

## Step 3 - Create env file
- Command: `cp .env.example .env`
- Why: all services read settings from `.env`.
- Success: `.env` file exists.
- Failure: "No such file" -> run from project root.

## Step 4 - Set secrets (do this once)
Open `.env` in an editor and set:
- `APP_KEY` -> generate with `php -r "echo base64_encode(random_bytes(32));"` (run inside any PHP-capable container later).
- `DB_PASSWORD` (and any other password fields present in your `.env`, e.g. `NEO4J_PASSWORD`) -> choose strong values.
- Why: insecure defaults can be abused.

## Step 5 - Create Docker networks
- Command: `make network`
- What it does: creates external networks `hawki-network` and `hosting_network` used by compose.
- Success: message "already exists" or created.
- Failure: Docker not running -> start Docker.

## Step 6 - Start services
- Command: `make up-core`
- What it does:
  - Uses `docker-compose.yml` as the base file.
  - Linux: auto-enables `docker-compose-gpu-override.yml` when `nvidia-smi` is available.
  - macOS/non-Linux: stays in CPU mode by default.
  - Pulls Ollama models `bge-m3`, `llama3.1:8b`, `llama3.2:1b`.
- Success: `docker ps` shows containers like `hawki_rag_app`, `hawki_qdrant`, `hawki_rag_bridge`, `hawki_rag_rerank`, `hawki_rag_neo4j`, `hawki_ollama`, `mariadb`, `phpmyadmin`.
- Failure: missing env vars -> recheck `.env`; GPU errors on Linux -> install NVIDIA runtime or run `USE_OLLAMA_GPU=0 make up-core`; port conflict (3306 or 8004) -> stop other services or change ports in compose.

## Step 7 - Health check everything
- Command: `make health`
- What it does: curls Qdrant, Ollama, reranker, bridge from inside containers.
- Success: lines ending with "OK".
- Failure: "FAIL" -> run `docker logs <container>` to see why (common: models still downloading, wrong passwords).
