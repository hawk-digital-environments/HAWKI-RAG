# 11. Repository Map (What each folder/file is for)

## Root
- `Makefile` — Helper targets (`network`, `up-core`, `health`, `ingest`, logs/restart helpers) using docker compose and container exec.
- `docker-compose.yml` — Defines all containers, networks, volumes; internal-only services (bridge, reranker, RAG API, DBs).
- `Dockerfile` — Builds Laravel app image and Python RAG images (multi-stage).
- `docker/` — Nginx config, Ollama build Dockerfile, supervisor configs.
- `_documentation/` — All docs (this file plus 1–10).
- `.env.example` — Template for runtime configuration.
- `composer.json`, `package.json` — PHP/JS dependencies.

## Laravel (PHP) side
- `app/Http/Controllers/API/`
  - `HawkiRagProxyController.php` — Proxies user queries to bridge.
  - `IngestController.php` — Starts/stops ingest processes, validates paths, writes status.
  - `IngestStatusController.php` — Reads/updates ingest status and logs.
  - `RagHealthController.php` — Health checks for RAG API/bridge.
- `app/Services/GraphService/Neo4jAdmin.php` — Clears all nodes/edges in Neo4j.
- `config/config.php` — RAG URLs, models, log paths.
- `routes/api.php` (standard Laravel) — Maps API routes to controllers above.
- `storage/` — Logs (`storage/logs`), shared files (`storage/app/public`, bound to shared volume).

## Python side
- `python_rag/` — FastAPI bridge, RAG-Anything API, reranker.
  - `app/` — FastAPI entrypoints.
  - `ingest/ingest_crawled.py` — Main ingest script.
  - `pipeline/` — Query logic and pipeline helpers.
  - `rerank/` — Local reranker app.
  - `requirements.txt` — Python deps.
  - `rag_storage/` — Working dir volume for RAG API.

## Assets and build
- `resources/` — Laravel views/assets (standard).
- `public/` — Public web root (served by Nginx).

## Volumes (from compose)
- `rawki_shared_storage` — Shared files between Laravel and bridge (`storage/app/public` ↔ `/app/shared`).
- `qdrant_data`, `neo4j_data`, `hawki_mariadb_data`, `rag_storage`, `ollama` — Persist DB/model data.

## Logs & statuses
- Laravel logs: `storage/logs/laravel.log`.
- Ingest logs: `storage/logs/ingest_progress_*.log` and status JSONs.
- Bridge/RAG API logs: via `docker logs <container>`.
