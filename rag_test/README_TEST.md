# README_TEST

## What To Run

1. Validate config and fairness rules:

```bash
python3 rag_test/scripts/validate_fairness.py
```

Expected:
- `Fairness validation passed.`
- log output in `stdout`

2. Prepare a sampled local benchmark corpus:

```bash
python3 rag_test/scripts/prepare_test_data.py
```

Expected:
- `100` folders copied by default from `RAG_TEST_SOURCE_VOLUME_PATH`
- manifest written to [copied_manifest.json](/home/ixdlab/RAG/HAWKI-RAG/rag_test/data_test/copied_manifest.json) after the first run
- log file at [prepare_test_data.log](/home/ixdlab/RAG/HAWKI-RAG/rag_test/logs/prepare_test_data.log)

3. Build one Qdrant collection per model:

```bash
python3 rag_test/scripts/build_qdrant_collections.py
```

Expected:
- separate collections for `bge-m3`, `qwen3-embedding`, and `nomic-embed-text`
- collection build manifest at [collection_build_manifest.json](/home/ixdlab/RAG/HAWKI-RAG/rag_test/logs/collection_build_manifest.json)
- per-step logs in [build_qdrant_collections.log](/home/ixdlab/RAG/HAWKI-RAG/rag_test/logs/build_qdrant_collections.log)

4. Run retrieval benchmark:

```bash
python3 rag_test/scripts/run_retrieval_benchmark.py
```

Expected:
- a new run folder under `rag_test/results/<run_id>/`
- retrieval outputs:
  - `summary.json`
  - `summary.csv`
  - `per_query.json`

5. Run graph benchmark:

```bash
python3 rag_test/scripts/run_graph_benchmark.py --run-id <run_id>
```

Expected:
- graph outputs inside the same run folder:
  - `graph/entity_link/summary.json`
  - `graph/entity_link/summary.csv`
  - `graph/entity_link/per_case.json`
  - `graph/neighbor_evidence/summary.json`
  - `graph/neighbor_evidence/summary.csv`
  - `graph/neighbor_evidence/per_case.json`

6. Export flattened summary:

```bash
python3 rag_test/scripts/export_results.py --run-id <run_id>
```

Expected:
- `export_summary.csv`
- `export_summary.json`
- printed winners by headline metric

7. Run everything:

```bash
python3 rag_test/scripts/run_all.py --prepare-data
```

## What To Expect In Logs

If something fails, logs should tell you:
- which script failed
- which function failed
- which model was active
- which query or graph case failed
- which collection or path was being used

Primary log locations:
- [logs](/home/ixdlab/RAG/HAWKI-RAG/rag_test/logs)
- `results/<run_id>/logs/run.log`

## Quick Review Flow

When reviewing code and outputs, check in this order:

1. [benchmark.php](/home/ixdlab/RAG/HAWKI-RAG/rag_test/config/benchmark.php)
2. [README.md](/home/ixdlab/RAG/HAWKI-RAG/rag_test/README.md)
3. [README_TEST.md](/home/ixdlab/RAG/HAWKI-RAG/rag_test/README_TEST.md)
4. [prepare_test_data.py](/home/ixdlab/RAG/HAWKI-RAG/rag_test/scripts/prepare_test_data.py)
5. [build_qdrant_collections.py](/home/ixdlab/RAG/HAWKI-RAG/rag_test/scripts/build_qdrant_collections.py)
6. [run_retrieval_benchmark.py](/home/ixdlab/RAG/HAWKI-RAG/rag_test/scripts/run_retrieval_benchmark.py)
7. [run_graph_benchmark.py](/home/ixdlab/RAG/HAWKI-RAG/rag_test/scripts/run_graph_benchmark.py)
8. The sample CSV outputs committed under `rag_test/results/sample_run/`

## Sample Output Files In Repo

A committed sample output tree is included so you can inspect the CSV schema before the first run:

- [summary.csv](/home/ixdlab/RAG/HAWKI-RAG/rag_test/results/sample_run/retrieval/summary.csv)
- [summary.csv](/home/ixdlab/RAG/HAWKI-RAG/rag_test/results/sample_run/graph/entity_link/summary.csv)
- [summary.csv](/home/ixdlab/RAG/HAWKI-RAG/rag_test/results/sample_run/graph/neighbor_evidence/summary.csv)
- [export_summary.csv](/home/ixdlab/RAG/HAWKI-RAG/rag_test/results/sample_run/export_summary.csv)

