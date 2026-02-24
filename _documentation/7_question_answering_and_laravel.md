# 7. Question Answering Flow & Laravel Internals

## End-to-end answer flow
1) User asks a question in the web UI (Nginx → Laravel).
2) Laravel sends the question to RAG API (`raganything_api_gpu:8003` inside network).
3) RAG API retrieves top chunks from Qdrant, optionally reranks via `hawki_rag_rerank`.
4) RAG API calls Ollama model (`llama3.1:8b`) to write an answer using those chunks.
5) Answer returns to Laravel and is shown to the user.

## Key Laravel pieces (files to know)
- `config/config.php`: URLs, model defaults, log paths for ingest/pipeline.
- Controllers:
  - `API/HawkiRagProxyController`: proxy query calls to bridge.
  - `API/IngestController`: start/stop ingest, validate paths, build python command.
  - `API/IngestStatusController`: read status/log files, update status when ingest finishes.
  - `API/RagHealthController`: tries RAG API and bridge health endpoints.
- Service:
  - `Services/GraphService/Neo4jAdmin`: wipes all nodes/edges in Neo4j.

## Routes (concept)
- API routes live in `routes/api.php` (standard Laravel). They map HTTP paths to the controllers above.

## Storage layout (important paths)
- Shared files: `storage/app/public` (mounted volume).
- Logs: `storage/logs/ingest_*.log`, `pipeline_status.json`.
- Status: `storage/logs/ingest_status.json` (and neo4j variants).

## Common artisan commands (inside app container)
- `php artisan migrate` — create DB tables; needed once after install.
- `php artisan queue:work` — process queued jobs if used.
- `php artisan config:clear` — reload env after changes.
- Success: command exits 0 and prints action; Failure: check message, usually missing DB or APP_KEY.

