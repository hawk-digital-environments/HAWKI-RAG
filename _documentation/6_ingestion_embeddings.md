# 6. Ingestion & Embeddings (No Steps Skipped)

## What happens during ingestion
1) Files are read from the shared volume (host path `storage/app/public`, container path `/app/shared`).
2) Text is chunked (default 3200 chars, 100 overlap).
3) Embeddings are created with `bge-m3` via Ollama.
4) Vectors stored in Qdrant; optional graph extraction with `llama3.2:1b` stored in Neo4j.
5) Logs and status are written to `storage/logs/ingest_*`.

## Prepare your data
- Place your folder under `storage/app/public/<foldername>` on host.
- Inside bridge container it appears as `/app/shared/<foldername>`.

## Run ingestion (inside container for no exposed ports)
- Command:
```
docker exec hawki_rag_bridge sh -lc "python /app/ingest/ingest_crawled.py \
  --root /app/shared/<foldername> \
  --base-url http://localhost:8000 \
  --provider ollama \
  --graph \
  --batch 16"
```
- What it does: reads files, chunks, embeds, writes to Qdrant/Neo4j.
- Success looks like: bridge logs end with `INGEST_DONE`; Qdrant shows new collection named `<foldername>`.
- Failure examples:
  - “Path must be within shared root” → ensure path starts with `/app/shared/`.
  - “Connection refused” → check `hawki_rag_bridge` running (`docker ps`); rerun `make up-rag`.

## Monitoring ingest progress
- View cached log: `docker exec hawki_rag_bridge tail -n 40 /var/www/storage/logs/ingest_progress_cache.log` (path matches Laravel volume).
- Check status JSON: `docker exec hawki_rag_app cat storage/logs/ingest_status.json`.

## Re-running or stopping
- Stop by killing process ID stored in status file (API supports stop; simplest is restart container if unsure).
- Re-run with `--resume` (default) or `--start` to force fresh ingest.

