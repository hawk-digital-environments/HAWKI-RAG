# 5. Environment, Database, Queue (Step-by-Step)

## Environment (.env) — every key explained
- `APP_NAME`: shows in UI; set to “HAWKI RAG”.
- `APP_URL`: public URL used by Laravel (template value is `http://localhost:8080`; this public endpoint is typically provided by an external reverse proxy).
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`: database access (values depend on your local `.env` vs `.env.example` / compose setup).
- `NEO4J_HTTP_URL=http://hawki_rag_neo4j:7474`, `NEO4J_USER`, `NEO4J_PASSWORD`: graph DB login.
- `QDRANT_HTTP_URL=http://qdrant:6333`: vector DB endpoint.
- `HAWKI_RAG_BRIDGE_URL` (HAWKI setup): ingest bridge URL.
- `HAWKI_RAG_API_URL`: question-answer API URL (the app config supports either).
- `HAWKI_RAG_SHARED_ROOT`: shared files path inside containers.
- `OLLAMA_API_URL`: model host (`http://ollama:11434/api` or compose alias `http://hawki_ollama:11434/api`).
- `OLLAMA_EMBED_MODEL`: embedding model used by current config defaults.
- `GRAPH_OLLAMA_RAG_MODEL`: graph extraction model fallback/default.
- Secrets to set: `APP_KEY`, DB/Neo4j passwords.

## Database setup
1) Start containers (`make up-core`).
2) Run migrations inside app container:
   - Command: `docker exec -it hawki_rag_app php artisan migrate`
   - What it does: creates Laravel tables.
   - Success: “Migrated”.
   - Failure: SQLSTATE errors → check DB credentials in `.env` and that `mariadb` is running (`docker ps`).

## Queue setup
- Queue driver depends on `.env` (`QUEUE_CONNECTION`). Common values:
  - `sync` (runs jobs inline; simple local setup)
  - `database` (uses MariaDB table `jobs`)
- Start a worker:
  - Command: `docker exec -it hawki_rag_app php artisan queue:work`
  - Success: “Processing: …”.
  - Failure: table missing → run `php artisan queue:table && php artisan migrate`.
- RabbitMQ is not part of the default compose stack in this repository. Use `sync` or `database` queue drivers unless you wire an external RabbitMQ service.

## Why each command matters
- `php artisan migrate`: without it, Laravel cannot store jobs/sessions used by the UI.
- `queue:work`: needed if any background jobs are queued (e.g., long tasks).
