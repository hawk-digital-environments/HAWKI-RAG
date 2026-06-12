# 7. Commands Catalogue

All commands run from project root unless noted. Use `docker ps` to see container names.

| Command | Purpose |
|---|---|
| `make network` | Create Docker networks `hawki-network` and `hosting_network` required by Compose. |
| `make up-core` | Start full stack from `docker-compose.yml`, including PostgreSQL, Temporal, Temporal UI, Temporal workers, and required Ollama models. |
| `make health` | Run internal health checks for Qdrant, Ollama, reranker, and bridge. |
| `make logs-core` | Stream Compose logs for core and stack services. |
| `docker exec -it hawki_rag_app php artisan migrate` | Create database tables. |
| `docker compose up -d postgres temporal temporal-ui qdrant hawki_rag_neo4j hawki_rag_bridge hawki_rag_app` | Start the local Temporal/PostgreSQL/RAG stack. |
| `docker compose up -d hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker` | Start all Temporal RAG ingestion workers. |
| `docker exec -it hawki_rag_app php artisan pipeline:workers` | Print Temporal worker containers, task queues, and registered workflow/activity names. |
| `docker exec -it hawki_rag_app php artisan pipeline:start-task --source-url=https://example.edu --refresh-cadence=daily` | Start `IngestSourceWorkflow` from Laravel and create/update a daily Temporal schedule. |
| `docker exec hawki_rag_bridge sh -lc "python -m application.cli.commands.ingest_crawled --root /app/shared/<folder> --base-url http://localhost:8000 --provider ollama --graph --batch 16"` | Load files into Qdrant and Neo4j. |
| `docker exec hawki_rag_app tail -n 40 /var/www/html/storage/logs/ingest_progress_cache.log` | Check latest ingest progress log lines. |
| `docker compose logs -f hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker` | Follow Temporal worker logs during a run. |
