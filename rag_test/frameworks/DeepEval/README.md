# DeepEval RAG Quickstart (Step-by-Step)

This framework implements the official DeepEval RAG quickstart flow:
- End-to-end RAG eval
- Retriever-only eval
- Generator-only eval
- Multi-turn RAG eval

Doc reference:
- https://deepeval.com/docs/getting-started-rag

## 1) Install

```bash
python3 -m pip install -r rag_test/frameworks/DeepEval/requirements.txt
```

Optional (recommended by DeepEval):
```bash
export CONFIDENT_API_KEY="confident_us_..."
export CONFIDENT_TRACE_FLUSH=1
```

## 2) Prepare dataset

Use:
- [sample_eval.jsonl](/home/rnonk/RAG/RAWKI/rag_test/frameworks/DeepEval/datasets/sample_eval.jsonl) for e2e/retriever/generator
- [sample_conversation.jsonl](/home/rnonk/RAG/RAWKI/rag_test/frameworks/DeepEval/datasets/sample_conversation.jsonl) for multi-turn

Schema for `sample_eval.jsonl`:
- `question`
- `answer`
- `contexts` (list of strings)
- `ground_truth` (optional but recommended)

## 3) End-to-end RAG eval

```bash
python3 rag_test/frameworks/DeepEval/run_deepeval.py \
  --mode e2e \
  --source jsonl \
  --input rag_test/frameworks/DeepEval/datasets/sample_eval.jsonl \
  --output rag_test/results/deepeval/e2e_latest \
  --model-provider ollama \
  --model-name deepseek-r1 \
  --ollama-base-url http://127.0.0.1:11434
```

## 4) Retriever-only eval (component tracing)

```bash
python3 rag_test/frameworks/DeepEval/run_deepeval.py \
  --mode retriever \
  --source live \
  --rag-base-url http://hawki_rag_bridge:8000 \
  --input rag_test/frameworks/DeepEval/datasets/sample_eval.jsonl \
  --output rag_test/results/deepeval/retriever_latest \
  --model-provider ollama \
  --model-name deepseek-r1 \
  --ollama-base-url http://hawki_ollama:11434
```

## 5) Generator-only eval (component tracing)

```bash
python3 rag_test/frameworks/DeepEval/run_deepeval.py \
  --mode generator \
  --source live \
  --rag-base-url http://hawki_rag_bridge:8000 \
  --input rag_test/frameworks/DeepEval/datasets/sample_eval.jsonl \
  --output rag_test/results/deepeval/generator_latest \
  --model-provider ollama \
  --model-name deepseek-r1 \
  --ollama-base-url http://hawki_ollama:11434
```

## 6) Multi-turn RAG eval

```bash
python3 rag_test/frameworks/DeepEval/run_deepeval.py \
  --mode multi_turn \
  --input rag_test/frameworks/DeepEval/datasets/sample_conversation.jsonl \
  --output rag_test/results/deepeval/multi_turn_latest \
  --model-provider ollama \
  --model-name deepseek-r1 \
  --ollama-base-url http://127.0.0.1:11434
```

## Notes
- For `--source live`, run from a network context that can reach your bridge URL.
- In your Docker stack, easiest is:
  - `docker exec hawki_rag_app ... --rag-base-url http://hawki_rag_bridge:8000`
- The script writes local artifacts even when cloud upload is not configured.
