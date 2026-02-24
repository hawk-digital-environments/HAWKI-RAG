# 5. Environment, Database, Queue (Step-by-Step)

## Environment (.env) — every key explained
- `APP_NAME`: shows in UI; set to “HAWKI RAG”.
- `APP_URL`: `http://localhost:8080` (Nginx public port).
- `DB_HOST=mariadb`, `DB_PORT=3306`, `DB_DATABASE=rag_db`, `DB_USERNAME`/`DB_PASSWORD`: MariaDB access.
- `NEO4J_HTTP_URL=http://hawki_rag_neo4j:7474`, `NEO4J_USER`, `NEO4J_PASSWORD`: graph DB login.
- `QDRANT_HTTP_URL=http://qdrant:6333`: vector DB endpoint.
- `HAWKI_RAG_BRIDGE_URL=http://hawki_rag_bridge:8000`: ingest bridge.
- `HAWKI_RAG_API_URL=http://raganything_api_gpu:8003`: question-answer API.
- `HAWKI_RAG_SHARED_ROOT=/var/www/storage/app/public`: shared files inside Laravel container.
- `OLLAMA_API_URL=http://ollama:11434/api`: model host.
- Secrets to set: `APP_KEY`, DB passwords, RabbitMQ passwords.

## Database setup
1) Start containers (`make up-core up-rag`).
2) Run migrations inside app container:
   - Command: `docker exec -it hawki_rag_app php artisan migrate`
   - What it does: creates Laravel tables.
   - Success: “Migrated”.
   - Failure: SQLSTATE errors → check DB credentials in `.env` and that `mariadb` is running (`docker ps`).

## Queue setup
- Default queue driver: `database` (uses MariaDB table `jobs`).
- Start a worker:
  - Command: `docker exec -it hawki_rag_app php artisan queue:work`
  - Success: “Processing: …”.
  - Failure: table missing → run `php artisan queue:table && php artisan migrate`.
- RabbitMQ is available but not required by default; change `.env` `QUEUE_CONNECTION` to `rabbitmq` only after configuring.

## Why each command matters
- `php artisan migrate`: without it, Laravel cannot store jobs/sessions used by the UI.
- `queue:work`: needed if any background jobs are queued (e.g., long tasks).

