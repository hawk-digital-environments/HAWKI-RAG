# 8. Commands Catalogue (What, Why, Output, Fix)

All commands run from project root unless noted. Use `docker ps` to see container names.

## make network
- What: create Docker network `hawki-network`.
- Why: containers need a shared network.
- Command: `make network`
- Success: “already exists” or created; exit 0.
- Failure: Docker not running → start Docker and rerun.

## make up-core
- What: build app image; start Qdrant, MariaDB, Nginx, Ollama.
- Why: base stack to serve UI and storage.
- Command: `make up-core`
- Success: `docker ps` lists `hawki_rag_app`, `hawki_rag_nginx`, `hawki_qdrant`, `hawki_ollama_*`, `mariadb`.
- Failure: port conflict (8080/3306) → stop conflicting service or change compose ports; build error → read build log.

## make up-rag
- What: build/start bridge, reranker, RAG API, RabbitMQ.
- Why: enable ingest and QA.
- Command: `make up-rag`
- Success: containers `hawki_rag_bridge`, `hawki_rag_rerank`, `raganything_api_gpu`, `hawki_rag_rabbitmq`.
- Failure: env missing → check `.env`; GPU issue → set `COMPOSE_PROFILES=cpu make up-core up-rag`.

## make health
- What: internal health curls (Qdrant, Ollama, reranker, bridge).
- Why: quick verification all services respond.
- Command: `make health`
- Success: each line ends with “OK”.
- Failure: “FAIL/WARN” → run `docker logs <container>`; often models still downloading.

## php artisan migrate
- Where: inside app container.
- Command: `docker exec -it hawki_rag_app php artisan migrate`
- What: create DB tables.
- Success: “Migrated”.
- Failure: SQLSTATE… → check DB credentials and that `mariadb` is up.

## php artisan queue:work
- Command: `docker exec -it hawki_rag_app php artisan queue:work`
- Why: run background jobs (if used).
- Success: “Processing”.
- Failure: missing jobs table → `php artisan queue:table && php artisan migrate`.

## Ingest documents
- Command:
```
docker exec hawki_rag_bridge sh -lc "python /app/ingest/ingest_crawled.py \
  --root /app/shared/<folder> --base-url http://localhost:8000 \
  --provider ollama --graph --batch 16"
```
- Why: load files into Qdrant/Neo4j.
- Success: logs show `INGEST_DONE`.
- Failure: path error → ensure folder under `/app/shared`; connection error → verify bridge running.

## Check ingest log
- Command: `docker exec hawki_rag_bridge tail -n 40 /var/www/storage/logs/ingest_progress_cache.log`
- Success: recent lines printed.
- Failure: file missing → ingest not started yet.

