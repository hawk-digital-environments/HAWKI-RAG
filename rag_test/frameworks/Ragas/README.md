# Ragas

Ragas is an open-source framework for evaluating RAG systems. It helps measure:
- factual accuracy / faithfulness
- answer relevance
- retrieval quality (how well retrieved context matches the question)

This folder provides a minimal, repo-local Ragas integration so you can run standard RAG evals alongside your custom `rag_test` pipeline.

## What is included
- `run_ragas.py`: CLI runner for Ragas metrics
- `config.yaml`: framework-local defaults
- `datasets/sample_eval.jsonl`: starter dataset schema
- `requirements.txt`: optional dependencies for this framework only

## Install

```bash
python3 -m pip install -r rag_test/frameworks/Ragas/requirements.txt
```

## Dataset schema (JSONL)
Each line must include:

```json
{
  "question": "...",
  "answer": "...",
  "contexts": ["...", "..."],
  "ground_truth": "..."
}
```

`ground_truth` is optional for some metrics, but recommended.

## Run

```bash
python3 rag_test/frameworks/Ragas/run_ragas.py \
  --input rag_test/frameworks/Ragas/datasets/sample_eval.jsonl \
  --output rag_test/results/ragas/latest
```

This runs in `keyless` mode by default when `OPENAI_API_KEY` is not set.

Optional modes:

```bash
# Force keyless mode
python3 rag_test/frameworks/Ragas/run_ragas.py \
  --mode keyless \
  --input rag_test/frameworks/Ragas/datasets/sample_eval.jsonl \
  --output rag_test/results/ragas/latest

# LLM-based mode (requires OPENAI_API_KEY)
export OPENAI_API_KEY=your_key_here
python3 rag_test/frameworks/Ragas/run_ragas.py \
  --mode llm \
  --input rag_test/frameworks/Ragas/datasets/sample_eval.jsonl \
  --output rag_test/results/ragas/latest
```

Outputs:
- `summary.json`
- `summary.csv`
- `per_case.json`

## Notes
- `keyless` mode uses non-LLM Ragas metrics and does not require any API key.
- `llm` mode uses faithfulness/relevancy/context metrics and needs an LLM API key.
