# 7. Commands Catalogue

All commands run from project root unless noted. Use `docker ps` to see container names.

| Command | Purpose |
|---|---|
| `make network` | Create Docker networks `hawki-network` and `hosting_network` required by Compose. |
| `make up-core` | Build images, migrate safely, and start production mode with the UI on `http://localhost:8080`. |
| `make up-core-server` | Start production mode for an external HTTPS reverse proxy without publishing a host UI port. |
| `make up-core-local` | Reuse service images, start the source-mounted development stack, and publish fresh Vite/Svelte assets. |
| `BUILD_STACK=1 make up-core-local` | Rebuild service images before starting development; use after Dockerfile or locked dependency changes. |
| `make migration-test` | Run isolated PostgreSQL upgrade scenarios against a temporary schema in the active database. |
| `make publish-ui` | Rebuild and publish only the Vite/Svelte UI assets into the running `hawki_rag_app` container. |
| `make health` | Run internal health checks for Qdrant, Ollama, reranker, and bridge. |
| `make logs-core` | Stream Compose logs for core and stack services. |
| `docker exec -it hawki_rag_app php artisan migrate` | Create database tables. |
| `docker exec -it hawki_rag_app php artisan pipeline:workers` | Print Temporal worker containers, task queues, and registered workflow/activity names. |
| `docker exec -it hawki_rag_app php artisan pipeline:start-task --source-url=https://example.edu --refresh-cadence=daily` | Start `IngestSourceWorkflow` from Laravel and create/update a daily Temporal schedule. |
| `docker compose logs -f hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker` | Follow Temporal worker logs during a run. |
