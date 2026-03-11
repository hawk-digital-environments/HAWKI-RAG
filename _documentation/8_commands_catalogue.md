# 8. Commands Catalogue (What, Why, Output, Fix)

All commands run from project root unless noted. Use `docker ps` to see container names.

## make network
- What: create Docker networks `hawki-network` and `hosting_network`.
- Why: compose expects these external networks.
- Command: `make network`
- Success: "already exists" or created; exit 0.
- Failure: Docker not running -> start Docker and rerun.

## make up-core
- What: start the full stack from `docker-compose.yml` (plus optional GPU override) and pull required Ollama models.
- Why: single entrypoint to bring services up for ingest + QA.
- Command: `make up-core`
- Success: `docker ps` lists containers such as `hawki_rag_app`, `hawki_qdrant`, `hawki_rag_bridge`, `hawki_rag_rerank`, `hawki_rag_neo4j`, `hawki_ollama`, `mariadb`, `phpmyadmin`.
- Failure: env missing -> check `.env`; Linux GPU runtime issue -> install NVIDIA runtime or run `USE_OLLAMA_GPU=0 make up-core`; port conflict (3306/8004) -> stop conflicting service or change compose ports.

## make health
- What: internal health curls (Qdrant, Ollama, reranker, bridge).
- Why: quick verification all services respond.
- Command: `make health`
- Success: each line ends with "OK".
- Failure: "FAIL/WARN" -> run `docker logs <container>`; often models still downloading.

## make logs-core
- What: stream compose logs for core + stack services.
- Why: live debugging while starting, ingesting, or querying.
- Command: `make logs-core`
- Success: live logs print continuously.
- Failure: no output -> verify containers are running with `docker ps`; if compose warns about missing legacy service names (`mysql`, `hawki_rag_nginx`), ignore and use `docker compose logs <service>`.

## php artisan migrate
- Where: inside app container.
- Command: `docker exec -it hawki_rag_app php artisan migrate`
- What: create DB tables.
- Success: "Migrated".
- Failure: SQLSTATE... -> check DB credentials and that `mariadb` is up.

## php artisan queue:work
- Command: `docker exec -it hawki_rag_app php artisan queue:work`
- Why: run background jobs (if used).
- Success: "Processing".
- Failure: missing jobs table -> `php artisan queue:table && php artisan migrate`.

## Ingest documents
- Command:
```
docker exec hawki_rag_bridge sh -lc "python /app/ingest/ingest_crawled.py \
  --root /app/shared/<folder> --base-url http://localhost:8000 \
  --provider ollama --graph --batch 16"
```
- Why: load files into Qdrant/Neo4j.
- Success: logs show `INGEST_DONE`.
- Failure: path error -> ensure folder under `/app/shared`; connection error -> verify bridge running.

## Check ingest log
- Command: `docker exec hawki_rag_app tail -n 40 /var/www/html/storage/logs/ingest_progress_cache.log`
- Success: recent lines printed.
- Failure: file missing -> ingest not started yet.
