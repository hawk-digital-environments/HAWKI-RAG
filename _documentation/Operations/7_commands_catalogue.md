# 7. Commands Catalogue

All commands run from project root unless noted. Use `docker ps` to see container names.

| Command | Purpose |
|---|---|
| `make network` | Create Docker networks `hawki-network` and `hosting_network` required by Compose. |
| `make up-core` | Start full stack from `docker-compose.yml` (plus optional GPU override) and pull required Ollama models. |
| `make health` | Run internal health checks for Qdrant, Ollama, reranker, and bridge. |
| `make logs-core` | Stream Compose logs for core and stack services. |
| `docker exec -it hawki_rag_app php artisan migrate` | Create database tables. |
| `docker exec -it hawki_rag_app php artisan queue:work` | Process background jobs when queueing is enabled. |
| `docker exec hawki_rag_bridge sh -lc "python -m application.cli.commands.ingest_crawled --root /app/shared/<folder> --base-url http://localhost:8000 --provider ollama --graph --batch 16"` | Load files into Qdrant and Neo4j. |
| `docker exec hawki_rag_app tail -n 40 /var/www/html/storage/logs/ingest_progress_cache.log` | Check latest ingest progress log lines. |
