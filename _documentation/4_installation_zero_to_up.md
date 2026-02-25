# 4. Installation: Zero to Running

Audience: never used terminal, Laravel, or RAG. Follow in order.

## Prerequisites (Why you need them)
- **Docker + Docker Compose v2**: runs every service in containers.
- **Git**: downloads the project.
- **Make**: runs scripted commands easily.
- **curl**: tests HTTP endpoints.

## Step 1 – Install prerequisites
- Mac: install Docker Desktop, `brew install git make curl`.
- Linux: install Docker Engine + Compose plugin, `sudo apt install git make curl`.
- Windows: install Docker Desktop + WSL2; do all commands inside WSL2 Ubuntu.

## Step 2 – Get the code
- Command: `git clone <repo-url> && cd HAWKI_RAG`
- What it does: downloads project; enters folder.
- Success: `ls` shows files like `Makefile`, `docker-compose.yml`.
- Failure: “permission denied” → fix folder permissions; “git: command not found” → install git.

## Step 3 – Create env file
- Command: `cp .env.example .env`
- Why: all services read settings from `.env`.
- Success: `.env` file exists.
- Failure: “No such file” → run from project root.

## Step 4 – Set secrets (do this once)
Open `.env` in an editor and set:
- `APP_KEY` → generate with `php -r "echo base64_encode(random_bytes(32));"` (run inside any PHP-capable container later).
- `DB_PASSWORD` (and any other password fields present in your `.env`, e.g. `NEO4J_PASSWORD`, `RABBITMQ_PASSWORD` if enabled) → choose strong values.
- Why: insecure defaults can be abused.

## Step 5 – Create Docker network
- Command: `make network`
- What it does: creates `hawki-network` for container-to-container communication.
- Success: message “already exists” or created.
- Failure: Docker not running → start Docker.

## Step 6 – Start core services
- Command: `make up-core`
- What it does: builds app image, starts Qdrant, MariaDB, Nginx, Ollama.
- Success: `docker ps` shows containers `hawki_rag_app`, `hawki_rag_nginx`, `hawki_qdrant`, `hawki_ollama_*`, `mariadb`.
- Failure: port conflict (8080 or 3306) → stop other services or change ports in compose.

## Step 7 – Start full RAG services
- Command: `make up-rag`
- What it does: builds/starts Python bridge, reranker, RAG API, RabbitMQ.
- Success: `docker ps` shows `hawki_rag_bridge`, `hawki_rag_rerank`, `raganything_api_gpu`, `hawki_rag_rabbitmq`.
- Failure: missing env vars → recheck `.env`; GPU errors → set `COMPOSE_PROFILES=cpu make up-core up-rag`.

## Step 8 – Health check everything
- Command: `make health`
- What it does: curls Qdrant, Ollama, reranker, bridge from inside containers.
- Success: lines ending with “OK”.
- Failure: “FAIL” → run `docker logs <container>` to see why (common: models still downloading, wrong passwords).
