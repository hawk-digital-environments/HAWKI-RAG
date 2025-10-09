# RAWKI / LightRAG Replication Story

This walkthrough explains, in plain steps, how a new user can reproduce the
RAWKI retrieval stack from the very first crawl to answering questions via the
RAWKI playground.

---

## Step 1 – Spin Up the Services

1. Install Docker and ensure the host has enough resources (≥ 8 GB RAM, ≥ 4 vCPU).
2. Pull the repository and move into it:
   ```bash
   git clone <repo-url> && cd RAWKI
   ```
3. Build and start the stack:
   ```bash
   composer install
   make up-core
   make up-rag
   ```
   These two launches:
   - `hawki_ollama` (embeddings, port 11434)
   - `hawki_qdrant` (vectors, port 6333)
   - `rawki_neo4j` (graph, ports 7475/7688)
   - `rawki_core` (LightRAG UI, port 8006)
   - `rawki_bridge` (FastAPI bridge, port 8009)
   - `rawki_rerank` (reranker, port 8008)

Verify health:
```bash
make test-services    # curls Qdrant, Neo4j, LightRAG UI, bridge, reranker
```

---

## Step 2 – Crawl the Source Website

Use the built-in Laravel command to fetch and normalize HAWKI content.

```bash
php artisan crawl:and-convert "https://www.hawk.de/" \
    --max-pages=100000 \
    --output-dir=storage/app/private/crawled-data/hawk-full \
    --label="hawk-full" \
    --image-exceptions="data:image,.svg,icon,favicon,logo,sprite,placeholder" \
    --date="meta[property='og:updated_time']"
```

Outcome: the directory `storage/app/private/crawled-data/hawk-full` now contains
one folder per page with Markdown text, metadata, and optional attachments.

---

## Step 3 – Plan the Ingest

Before posting into Qdrant/Neo4j, decide how much work will happen.

### 3a. Quick Estimate (no embedding, no HTTP)
```bash
python3 scripts/ingest_crawled.py \
  --root storage/app/private/crawled-data/hawk-full \
  --estimate-only \
  --chunk-chars 3200 \
  --chunk-overlap 100
```
This prints a JSON preview of planned Qdrant points.

### 3b. Dry Run (hits FastAPI bridge, skips embeddings/graph)
```bash
python3 scripts/ingest_crawled.py \
  --root storage/app/private/crawled-data/hawk-full \
  --base-url http://localhost:8009 \
  --collection embeddings_hawk \
  --dry
```
You’ll see batch counts and a summary file without spending GPU time.

---

## Step 4 – Live Ingest (Embeddings + Knowledge Graph)

Run the full ingestion when ready:
```bash
python3 scripts/ingest_crawled.py \
  --root storage/app/private/crawled-data/hawk-full \
  --base-url http://localhost:8009 \
  --provider ollama \
  --graph \
  --collection embeddings_hawk \
  --distance Cosine \
  --chunk-chars 3200 \
  --chunk-overlap 100 \
  --batch 8 \
  --timeout 1800
```

**Interactive resume prompt**  
If data was ingested before, the script spots the resume state and asks:
```
Type 'resume' to skip already-ingested docs or 'start' to process everything again [resume/start]:
```
- `resume` or Enter → reuses existing doc IDs, only new files are processed.  
- `start` → deletes the resume marker and re-embeds the whole corpus.

State files live under `storage/app/private/ingest-state/<hash>.json`.

When the script finishes:
- All chunks are embedded via Ollama (`bge-m3`).
- Vectors land in Qdrant (`embeddings_hawk`).
- Triplets become `(:Entity)-[:REL]->(:Entity)` in Neo4j.
- A summary is written to `public/ingest_summary.json`.

---

## Step 5 – Optional: Sync the LightRAG Playground Cache

The LightRAG UI (`rawki_core`) keeps a separate cache. To keep it aligned:
```bash
python3 scripts/ingest_to_lightrag.py \
  --root storage/app/private/crawled-data/hawk-full \
  --base-url http://localhost:8006 \
  --batch 8 \
  --timeout 180
```
This posts directly to `http://localhost:8006/documents/texts`, ensuring the UI
shows the same corpus as Qdrant/Neo4j.

---

## Step 6 – Check Storage Counts

**Qdrant:**
```bash
curl -s -X POST http://localhost:6333/collections/embeddings_hawk/points/count \
     -H 'Content-Type: application/json' -d '{"exact": true}'
```

**Neo4j:**
```bash
docker exec rawki_neo4j cypher-shell -u neo4j -p ixdlabPass123 \
  "MATCH (n:Entity) RETURN count(n) AS entities"
docker exec rawki_neo4j cypher-shell -u neo4j -p ixdlabPass123 \
  "MATCH ()-[r:REL]->() RETURN count(r) AS relationships"
```

**Resume state directory:**
```bash
ls storage/app/private/ingest-state
```

---

## Step 7 – Explore and Query

1. Open the LightRAG playground: `http://localhost:8006` (replace `localhost` with
   the server IP for remote use).
2. Use `/query` via the bridge for API calls:
   ```bash
   curl -s -X POST http://localhost:8009/query \
        -H "Content-Type: application/json" \
        -d '{"query":"What is HAWKI?", "top_k":5, "generate":true}'
   ```
3. The bridge embeds the query with Ollama, searches Qdrant, gathers Neo4j context,
   runs the reranker, and returns the answer + supporting snippets.

---

## Step 8 – Maintain the Stack

- **Resume files**: delete `storage/app/private/ingest-state/*.json` to force a full re-ingest.
- **Reset Neo4j**: `docker exec rawki_neo4j cypher-shell -u neo4j -p ixdlabPass123 "MATCH (n) DETACH DELETE n;"`
- **Ollama VRAM warnings**: informational; restart the container if crashes occur.
- **Timeout adjustments**: increase `--timeout` or reduce `--batch` when batches are huge.

---

## Step 9 – Troubleshoot Quickly

| Symptom                                 | Fix                                                         |
|-----------------------------------------|--------------------------------------------------------------|
| Embedding requests timeout              | Lower `--batch`, extend `--timeout`, ensure Ollama is idle. |
| Neo4j connection refused                | Wait for Neo4j to report healthy, then restart `rawki_core`.|
| Repeated ingestion of same docs         | Choose `resume` at the prompt; delete state file if needed. |
| UI missing new docs                     | Rerun `ingest_to_lightrag.py` to refresh the playground cache. |

---

## Step 10 – Summary

1. **Launch services** (`docker compose up`).  
2. **Crawl content** using the Laravel command.  
3. **Estimate or dry run** the ingest workload.  
4. **Ingest live**, choosing resume/start as needed.  
5. **Sync the LightRAG UI** (optional).  
6. **Verify counts** in Qdrant and Neo4j.  
7. **Query** via the UI or API.  
8. **Maintain and troubleshoot** with the quick fixes above.

Following these steps reproduces the entire RAWKI pipeline from scratch, ending
with a functional LightRAG playground backed by Qdrant and Neo4j.
