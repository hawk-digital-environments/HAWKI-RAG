# Vertex AI Gen AI Evaluation

This folder provides a Vertex AI evaluation scaffold for RAG testing.

It supports:
- preparing your local JSONL test set into Vertex-friendly tabular format
- optionally running cloud evaluation with Vertex AI (when GCP auth/project are configured)

## Included
- `run_vertex_eval.py`: dataset prep + optional cloud evaluation runner
- `config.yaml`: local defaults
- `requirements.txt`: framework dependencies
- `datasets/sample_eval.jsonl`: starter dataset schema

## Install

```bash
python3 -m pip install -r rag_test/frameworks/VertexAI/requirements.txt
```

## Dataset schema (JSONL)

Each line:

```json
{
  "question": "...",
  "answer": "...",
  "contexts": ["...", "..."],
  "ground_truth": "..."
}
```

## Prepare only (no cloud call)

```bash
python3 rag_test/frameworks/VertexAI/run_vertex_eval.py \
  --source jsonl \
  --input rag_test/frameworks/VertexAI/datasets/sample_eval.jsonl \
  --output rag_test/frameworks/VertexAI/evaluation/latest
```

Outputs:
- `prepared.csv`
- `prepared.jsonl`
- `summary.json`

## Use live Qdrant + Neo4j-backed RAG outputs

If your RAG API is running (for example on `http://localhost:8009`) and `/query` is backed by your current Qdrant + Neo4j, generate the evaluation dataset automatically:

```bash
python3 rag_test/frameworks/VertexAI/run_vertex_eval.py \
  --source live \
  --rag-base-url http://localhost:8009 \
  --provider ollama \
  --top-k 5 \
  --input rag_test/frameworks/VertexAI/datasets/sample_eval.jsonl \
  --output rag_test/frameworks/VertexAI/evaluation/live_latest
```

Input file for `--source live` only needs:

```json
{"question":"...", "ground_truth":"..."}
```

The script will call `/query`, collect:
- `answer` (fallback: top hit content),
- retrieved `contexts` (from hit payload content),
- and metadata (`hit_count`, `kg_count`),
then write Vertex-ready files.

Important:
- `--rag-base-url` is required in `--source live` mode.
- In your Docker setup, run from `hawki_rag_app` and use `http://hawki_rag_bridge:8000`.

## Run cloud evaluation (Vertex AI)

Prereqs:
- `gcloud auth application-default login`
- GCP project with Vertex AI API enabled

```bash
python3 rag_test/frameworks/VertexAI/run_vertex_eval.py \
  --input rag_test/frameworks/VertexAI/datasets/sample_eval.jsonl \
  --output rag_test/frameworks/VertexAI/evaluation/latest \
  --project YOUR_GCP_PROJECT \
  --location us-central1 \
  --run-cloud
```

Notes:
- Cloud evaluation depends on the installed Vertex AI SDK version and enabled metrics.
- If cloud eval is unavailable, the script still produces prepared files for manual upload/use.
