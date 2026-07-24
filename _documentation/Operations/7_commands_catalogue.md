# 7. Commands Catalogue

All commands run from project root unless noted. Use `docker ps` to see container names.

| Command | Purpose |
|---|---|
| `make network` | Create Docker networks `hawki-network` and `hosting_network` required by Compose. |
| `make up-core` | Start the full local stack, run migrations, pull required Ollama models, and publish fresh Vite/Svelte UI assets into the Laravel app. |
| `make publish-ui` | Rebuild and publish only the Vite/Svelte UI assets into the running `hawki_rag_app` container. |
| `make health` | Run internal health checks for Qdrant, Ollama, reranker, and bridge. |
| `make logs-core` | Stream Compose logs for core and stack services. |
| `docker exec -it hawki_rag_app php artisan migrate` | Create database tables. |
| `docker compose up -d postgres temporal qdrant hawki_rag_neo4j hawki_rag_bridge hawki_rag_app` | Start the local Temporal/PostgreSQL/RAG stack. |
| `docker compose up -d hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker` | Start all Temporal RAG ingestion workers. |
| `docker exec -it hawki_rag_app php artisan pipeline:workers` | Print Temporal worker containers, task queues, and registered workflow/activity names. |
| `docker exec -it hawki_rag_app php artisan pipeline:start-task --source-url=https://example.edu --refresh-cadence=daily` | Start `IngestSourceWorkflow` from Laravel and create/update a daily Temporal schedule. |
| `docker compose logs -f hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker` | Follow Temporal worker logs during a run. |
