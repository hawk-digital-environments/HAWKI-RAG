# 7. Commands Catalogue

All commands run from project root unless noted. Use `docker ps` to see container names.

| Command | Purpose |
|---|---|
| `make network` | Create Docker networks `hawki-network` and `hosting_network` required by Compose. |
| `make up-core` | Start the local stack, run migrations, and bring up the API, workers, and supporting services. |
| `make health` | Run internal health checks for Qdrant, Ollama, reranker, and bridge. |
| `make logs-core` | Stream Compose logs for core and stack services. |
| `docker exec -it hawki_rag_app php artisan migrate` | Create database tables. |
| `docker compose up -d postgres temporal temporal-ui qdrant hawki_rag_neo4j hawki_rag_bridge hawki_rag_app` | Start the local Temporal/PostgreSQL/RAG stack. |
| `docker compose up -d hawki-rag-temporal-workflow-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker` | Start all Temporal RAG ingestion workers. |
| `docker exec -it hawki_rag_app php artisan pipeline:workers` | Print Temporal worker containers, task queues, and registered workflow/activity names. |
| `docker compose logs -f hawki-rag-temporal-workflow-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker` | Follow Temporal worker logs during a run. |
