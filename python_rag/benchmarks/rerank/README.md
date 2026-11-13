# Reranker Benchmark

For real retrieval candidates and manual relevance judgments, use the
[retrieval evaluation workflow](EVALUATION.md). The `dataset.json` examples below
remain a synthetic development fixture.

Compare local CrossEncoder inference with remote rerankers through the LiteLLM
Python SDK. Each model ranks the same labeled documents; the benchmark reports
ranking quality, latency, and available API usage.

## Models

| Model | Identifier | Required environment variable |
| --- | --- | --- |
| Local Mixedbread | `mixedbread-ai/mxbai-rerank-base-v1` | None |
| Cohere | `cohere/rerank-v3.5` | `COHERE_API_KEY` |
| Local BGE | `BAAI/bge-reranker-v2-m3` | None |
| IxDLab Jina | `litellm_proxy/jinaai/jina-reranker-v3.5` | `LITELLM_PROXY_API_BASE`, `LITELLM_PROXY_API_KEY` |
| Voyage AI | `voyage/rerank-2.5` | `VOYAGE_API_KEY` |

## Running the benchmark

Run these commands from `python_rag` using `.venv` with LiteLLM `1.106.4`.
Local runs also need the bridge's `local-reranker` dependencies.

Dry run: show the plan without running inference.

```bash
.venv/bin/python -B benchmarks/rerank/benchmark.py --output /tmp/rerank-plan
```

Local benchmark on CPU, using cached weights:

```bash
.venv/bin/python -B benchmarks/rerank/benchmark.py \
  --execute --models local --output /tmp/rerank-local
```

Remote benchmark through the LiteLLM SDK:

```bash
.venv/bin/python -B benchmarks/rerank/benchmark.py \
  --execute --allow-remote --models cohere voyage jina \
  --max-remote-calls 9 --output /tmp/rerank-remote
```

Export the API keys in your shell first; use `--env-file ../.env` to load only the two gateway variables.
Missing keys skip that provider. Remote calls require both `--execute` and
`--allow-remote`. Add `--allow-download` to a local run to authorize model downloads.
The supplied dataset makes 9 remote calls across all three remote providers per repetition.
Calls run sequentially, with retries disabled and a 60-second remote timeout.

Unit tests use fake rerankers and make no inference calls:

```bash
LITELLM_LOCAL_MODEL_COST_MAP=True PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
  .venv/bin/python -B -m pytest -q -p no:cacheprovider benchmarks/rerank/test_benchmark.py
```

## Results

Results go to `benchmarks/rerank/results/` by default, or the directory given by
`--output`. Each run overwrites these files in that directory:

- `results.json`: full report, including original document IDs, indices, and scores.
- `summary.csv`: per-model quality metrics, latency percentiles, and local initialization time.
- `calls.csv`: individual successful calls, rankings, usage, and cost fields.
- `errors.csv` and `skips.csv`: failures and reasons calls or models were skipped.

The console prints a compact comparison table.

- **nDCG@10:** measures graded relevance and ordering in the top ten; higher is better, up to 1.
- **MRR@10:** reciprocal rank of the first relevant document in the top ten; higher is better.
- **Recall@10:** fraction of all labeled relevant documents found in the top ten.
- **P50/P95 latency:** median and 95th-percentile successful call times, including SDK/network overhead; lower is better.
- **API cost:** usage and SDK cost fields are recorded when available; missing values are unknown, and `actual_cost_usd` remains null.

Quality metrics average successful calls only, so check failures and query coverage
before comparing models. Local initialization is timed separately; the first
inference can still include warmup. Use more repetitions for useful latency percentiles.

## Configuration

- `models.json` lists model names, provider IDs, credentials, and official API bases.
  To add a model, copy an entry for the same provider, give it a unique `name`, and
  set a verified model identifier. Keep its provider and API base unchanged.
- `dataset.json` contains candidate documents and queries with relevance labels:
  0 = irrelevant, 1 = supporting, 2 = directly relevant; omitted labels count as 0.
  Labels reference unique candidate IDs. Use `--dataset path/to/dataset.json` for another dataset.
- `--models local cohere` selects configuration entry names; omitting it selects all.
- `--config path/to/models.json` loads a replacement model list.
- `--repetitions 5` runs every query five times per model; the default is 1.
- `--max-remote-calls 45` sets the global remote call limit; the default is 9,
  and failed calls count against it. Increase it alongside repetitions to cover every model.

## Notes

- Remote inference uses `litellm.rerank()` through `LiteLLMReranker`; Jina uses the existing IxDLab gateway, while Cohere and Voyage use their hosted APIs.
- Local CPU inference uses `LocalReranker`; all models receive the same extracted documents.
- The small synthetic dataset is a development fixture, not a reliable production-quality evaluation.
- Costs are SDK estimates/reports or unknown, not confirmed invoices.

## Mixedbread, BGE, and IxDLab Jina

Export `LITELLM_PROXY_API_BASE=https://ai.ixdlab.hawk.de` and your virtual key as
`LITELLM_PROXY_API_KEY`. Neither value is read from other variable names. The key
is never included in reports. The gateway must advertise the exact
`jinaai/jina-reranker-v3.5` alias in its authenticated `/v1/models` response;
otherwise Jina initialization fails before inference. The alias and virtual-key authentication were verified by a real SDK smoke test on 2026-11-13.

```bash
# Plan the three-model comparison (no inference).
.venv/bin/python -B benchmarks/rerank/benchmark.py --models local bge jina

# Three Jina requests, plus local inference on the same dataset.
.venv/bin/python -B benchmarks/rerank/benchmark.py \
  --execute --allow-remote --models local bge jina \
  --max-remote-calls 3 --env-file ../.env --output /tmp/rerank-three-models

# CPU smoke test: cached BGE weights only.
.venv/bin/python -B benchmarks/rerank/smoke.py --model bge

# Exactly one Jina rerank request after authenticated model discovery.
.venv/bin/python -B benchmarks/rerank/smoke.py --model jina --allow-remote
```

Both smoke tests use the same dataset-deletion query and three documents, validate
finite scores and unique original indices, and require the weather document not
to rank first. Add `--allow-download` to authorize BGE weights downloading.
Both CLIs accept `--env-file /path/to/credentials.env` to load only the two gateway
variables into that Python process; do not put keys in command arguments.

Gateway discovery reports `PermissionError` for denied credentials, `LookupError`
for an absent alias, and `ConnectionError` for other non-200 discovery responses.
Rerank failures report the SDK exception class and HTTP status without raw messages.
Historical files under `results/` are preserved unless you run with that output path.
