# 9. Troubleshooting (Symptoms → Cause → Fix)

## Port already in use (8080, 3306)
- Symptom: `bind: address already in use` on `make up-core`.
- Cause: another service on that port.
- Fix: stop other service or change port mapping in `docker-compose.yml` and `.env`.

## Models download slowly or fail
- Symptom: Ollama pulls hang; health check fails for Ollama.
- Cause: network slow or down.
- Fix: `docker exec hawki_ollama_gpu ollama pull bge-m3` manually; retry later.

## GPU not available
- Symptom: compose complains about GPU devices.
- Cause: no NVIDIA drivers or running on CPU-only machine.
- Fix: `COMPOSE_PROFILES=cpu make up-core` to force CPU profile.

## Ingest path rejected
- Symptom: “Path must be within shared root.”
- Cause: path not under `/app/shared`.
- Fix: put files in `storage/app/public/<folder>` (host) and use `/app/shared/<folder>` in ingest command.

## RAG API health fails (502)
- Symptom: `make health` warns or UI shows 502.
- Cause: `raganything_api_gpu` down or wrong URL.
- Fix: `docker logs raganything_api_gpu`; ensure `.env` points to the RAG API with `HAWKI_RAG_API_URL` (commonly `http://raganything_api_gpu:8003` in this repo); restart container.

## Database auth errors
- Symptom: SQLSTATE[HY000] during `php artisan migrate`.
- Cause: wrong DB password or DB not ready.
- Fix: confirm `.env` DB_* values match compose; wait a few seconds and rerun.

## Neo4j auth errors
- Symptom: 401/403 talking to Neo4j.
- Cause: password mismatch.
- Fix: set `NEO4J_USER/PASSWORD` same in `.env` and compose; recreate `hawki_rag_neo4j` if needed.

## Missing env variables
- Symptom: services exit immediately.
- Cause: `.env` incomplete.
- Fix: compare `.env` with `.env.example`; fill required keys; rerun `make up-core`.
