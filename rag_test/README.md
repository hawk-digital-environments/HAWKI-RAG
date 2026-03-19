# rag_test

`rag_test` is an offline benchmarking harness for the existing `python_rag` / RAG-Anything backend in this repo. It is built specifically for apples-to-apples evaluation of:

- `bge-m3`
- `qwen3-embedding`
- `nomic-embed-text`

It focuses on:

- Qdrant retrieval quality
- current-backend ingestion and query behavior
- graph-pipeline embedding support
- query groups for lexical lookup, semantic paraphrase, and relation-heavy graph-needed cases

The benchmark does not compare graph extraction LLM behavior. Neo4j remains the graph store, but vectors are benchmarked through the current backend's ingestion/query path plus offline graph-support tasks aligned to the same embedding model.

## Fairness rules

These rules are enforced in code and documented here because they are the core of a valid comparison:

1. One embedding model per collection.
2. Query embeddings must match document embeddings.
3. Graph-support tasks must use the same embedding model as the paired Qdrant collection.
4. Reranker stays enabled in config for reproducibility.
5. No hidden cross-model collection reuse or mixed-vector leakage.

The validator is:

```bash
python3 rag_test/scripts/validate_fairness.py
```

## Folder structure

```text
rag_test/
  README.md
  .env.example
  Makefile
  config/
    benchmark.php
  scripts/
    prepare_test_data.py
    build_qdrant_collections.py
    run_retrieval_benchmark.py
    run_graph_benchmark.py
    run_all.py
    export_results.py
    validate_fairness.py
  benchmark/
    queries/
    gold/
  graph_pipeline/
  retrieval/
  data_test/
  results/
  logs/
```

## Configuration

The central config lives in [benchmark.php](/home/ixdlab/RAG/HAWKI-RAG/rag_test/config/benchmark.php). Python scripts load it through the local `php` CLI so the package has one main config source, then apply those settings onto the existing backend modules.

Copy and edit the example env file if you want local overrides:

```bash
cp rag_test/.env.example rag_test/.env
```

Important config areas:

- Qdrant connection
- Neo4j connection
- collection naming and distance metric
- random seed
- source Docker volume path
- number of folders to copy
- top-k and graph k-values
- model registry for `bge-m3`, `qwen3-embedding`, and `nomic-embed-text`
- reranker settings, always enabled

## Prepare data

`prepare_test_data.py` copies a reproducible random subset of folder entries from a configurable source path into `rag_test/data_test`.

Example:

```bash
python3 rag_test/scripts/prepare_test_data.py \
  --source-path /var/lib/docker/volumes/rawki_shared_storage/_data \
  --count 20 \
  --seed 42
```

Outputs:

- copied folders under `rag_test/data_test/`
- manifest JSON under `rag_test/data_test/copied_manifest.json`
- preparation log under `rag_test/logs/prepare_test_data.log`

Assumption: source folders already contain crawled or project content. Unsupported or broken folders are skipped with logged errors.

## Build per-model Qdrant collections

Build separate collections for each enabled model:

```bash
python3 rag_test/scripts/build_qdrant_collections.py
```

This script:

- reads text-like files from `rag_test/data_test`
- sends documents through the current `python_rag` ingestion pipeline
- lets the backend handle chunking and embedding
- recreates one Qdrant collection per model
- writes a build manifest to `rag_test/logs/collection_build_manifest.json`

Collection naming is:

```text
{prefix}_{collection_suffix}
```

So a default run will create collections similar to:

- `ragtest_bge_m3`
- `ragtest_qwen3_embedding`
- `ragtest_nomic_embed_text`

## Phase 1: retrieval-only benchmark

Run:

```bash
python3 rag_test/scripts/run_retrieval_benchmark.py
```

Metrics:

- Recall@5
- Recall@10
- MRR@10
- nDCG@10

What gets logged:

- per-query metrics
- aggregated summary
- collection size
- latency
- config snapshot
- reranker state

This phase uses the current backend query pipeline in retrieval-only mode so the benchmark measures the real stack rather than a parallel search implementation.

## Phase 2: graph-support benchmark

Run:

```bash
python3 rag_test/scripts/run_graph_benchmark.py
```

Two offline tasks are included:

1. Entity/link candidate ranking
2. Neighbor evidence retrieval with graph-linked expansion

Entity/link metrics:

- Recall@k
- MRR
- Top-1 accuracy

Neighbor evidence outputs:

- seed recall at the configured seed cutoff
- Recall@k for evidence nodes
- MRR
- Top-1 accuracy

## Run everything

```bash
python3 rag_test/scripts/run_all.py --prepare-data
```

Or:

```bash
make -C rag_test all
```

## Export results

After a run:

```bash
python3 rag_test/scripts/export_results.py --run-id <run_id>
```

This writes:

- `results/<run_id>/export_summary.csv`
- `results/<run_id>/export_summary.json`

It also prints the best model by a few headline metrics.

## Result layout

Each benchmark run writes into:

```text
results/
  <run_id>/
    config_snapshot.json
    retrieval/
      summary.json
      summary.csv
      per_query.json
    graph/
      entity_link/
        summary.json
        summary.csv
        per_case.json
      neighbor_evidence/
        summary.json
        summary.csv
        per_case.json
    logs/
      run.log
```

## Benchmark file schemas

### Query files

Each file under `benchmark/queries/` uses:

```json
{
  "schema_version": 1,
  "queries": [
    {
      "id": "semantic_001",
      "group": "semantic_paraphrase",
      "language": "en",
      "text": "Example query text",
      "notes": "Optional note"
    }
  ]
}
```

### Retrieval gold

`benchmark/gold/retrieval_gold.json` uses:

```json
{
  "schema_version": 1,
  "queries": [
    {
      "query_id": "semantic_001",
      "relevant_doc_ids": ["doc_a", "doc_b"],
      "graded_relevance": {
        "doc_a": 3,
        "doc_b": 2
      }
    }
  ]
}
```

Important: `relevant_doc_ids` must match the document ids produced from your indexed dataset. The build manifest helps you map source files to generated `doc_id` values.

### Entity link gold

`benchmark/gold/entity_link_gold.json` uses:

```json
{
  "schema_version": 1,
  "cases": [
    {
      "id": "entity_001",
      "mention_text": "Borrowing desk",
      "correct_entity_id": "node_library_service",
      "candidates": [
        {"id": "node_library_service", "text": "Candidate text"},
        {"id": "node_it_helpdesk", "text": "Distractor text"}
      ]
    }
  ]
}
```

### Neighbor evidence gold

`benchmark/gold/neighbor_evidence_gold.json` uses:

```json
{
  "schema_version": 1,
  "cases": [
    {
      "id": "neighbor_001",
      "query": "Question text",
      "relevant_seed_ids": ["seed_a"],
      "relevant_evidence_ids": ["evidence_x"],
      "seed_candidates": [
        {
          "id": "seed_a",
          "text": "Seed chunk text",
          "neighbor_evidence_ids": ["evidence_x"]
        }
      ],
      "evidence_candidates": [
        {"id": "evidence_x", "text": "Neighbor node or chunk text"}
      ]
    }
  ]
}
```

## Assumptions and limitations

- This package benchmarks embeddings, not answer generation.
- The graph extraction LLM is intentionally out of scope.
- Qdrant collections must already be reachable from the benchmark runner.
- The retrieval gold files included here are starter examples and should be replaced with real gold annotations for meaningful scores.
- The benchmark harness is intentionally thin; ingest/query execution is delegated to the current backend where possible.
- The graph support tasks use the same backend provider wiring for embeddings.
- The reranker is kept enabled in config and defaults to backend `cosine` mode for offline reproducibility.

## Practical notes

- Use the same query set for all models.
- Keep the same chunking, top-k, and corpus across runs.
- Replace the sample gold files with your real benchmark labels before treating the output as decision-grade.
