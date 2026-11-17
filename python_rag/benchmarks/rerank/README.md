# Reranker Benchmark

This benchmark supports sequential ranking-quality comparisons and parallel
remote SDK requests using the same models and labeled candidate dataset.
Use `--load-test` for a larger synthetic payload and stepped
worker-burst test described below.

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
| IxDLab Jina | `litellm_proxy/jina-rerank` | `LITELLM_PROXY_API_BASE`, `LITELLM_PROXY_API_KEY` |
| Voyage AI | `voyage/rerank-2.5` | `VOYAGE_API_KEY` |

## Running the benchmark

Run these commands from `python_rag` using `.venv` with LiteLLM `1.106.4`.
Install the benchmark dependency group described below before executing metrics.
Local runs also need the bridge's `local-reranker` extra (shared client dependencies).

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
Calls run sequentially by default, with retries disabled and a 60-second remote timeout.

## Synthetic payload and concurrency sweep

`--load-test` generates a synthetic workload: six topics, four
documents per topic, and six rotating queries. Each request ranks all **24
documents**, totaling **38,452 document characters** (about 1,602 per document),
after the same production extraction used by normal benchmarks. Token counts
are reported only when returned by the provider, rather than estimated from
character counts.

From `python_rag`, using your existing bridge environment:

```bash
# Preview the complete plan; sends no discovery or inference requests.
.venv/bin/python benchmarks/rerank/benchmark.py --models jina --load-test \
  --levels 24,32,48,64 --per 1 --cooldown 3 \
  --max-remote-calls 168 --output /tmp/rerank-load-plan

# Execute the same 168-request sweep through the existing gateway.
.venv/bin/python benchmarks/rerank/benchmark.py --models jina --load-test \
  --env-file ../.env --execute --allow-remote \
  --levels 24,32,48,64 --per 1 --cooldown 3 \
  --max-remote-calls 168 --output /tmp/rerank-load
```

At level N, N workers each send `--per` sequential requests.
With `--per 1`, each level is one short burst;
`--per 8` sends eight requests per worker and needs a total budget of **1,344**
for the default levels. Workers start as threads become available, without a
synchronized start barrier. The measured peak may be below the requested N.
The SDK client is initialized once per selected model and reused across levels.
Cooldown applies between phases with no final pause. Models run one at a time.

Use `--levels` and `--per` in this mode, instead of `--concurrency`,
`--repetitions`, or `--dataset`. The global `--max-remote-calls` budget must
cover the full planned sweep, including all selected models. The default
budget of 9 is insufficient, so specify it explicitly. Failed attempts count
toward the budget; model-discovery calls remain separate.

Each phase prints live in-flight/peak/completion counts, then its summary.
JSON/CSV results are saved after every completed phase. Summaries include:

- Requested and observed peak concurrency, attempts, successes, and failures.
- All-attempt throughput, p50/p95 latency, and maximum latency, alongside
  successful-call throughput and p50/p95.
- Exception counts, individual HTTP error codes when available, and reported
  input tokens with the number of successful calls providing token usage.

As in the normal benchmark, latency measures the SDK adapter call; response
validation is performed afterward and contributes to phase wall time.
All-attempt p50/p95 fields include failed attempts; successful-call percentiles
exclude failures. The console load-test table uses the all-attempt values.
These generated documents have **no relevance judgments**, so quality fields
remain null and no qrels/runs files are generated. Use a new output directory
for each run to keep results separate from prior quality evaluations.

## Concurrent requests with the labeled dataset

Use `--concurrency N` to run up to N SDK calls in parallel for each selected
remote model. Models themselves are tested one after another. For Jina, the
supplied dataset has three queries, so 128 repetitions schedules 384 requests:

```bash
# Install bridge dependencies, then add the workspace-root benchmark group.
# --inexact retains the bridge packages during the group-only sync.
uv sync --frozen --package hawki-bridge
uv sync --frozen --only-group benchmark --inexact

# Preview; no inference calls.
.venv/bin/python benchmarks/rerank/benchmark.py --models jina \
  --concurrency 40 --repetitions 128 --max-remote-calls 384 \
  --output /tmp/rerank-jina-c40-plan

# Execute from python_rag with gateway credentials in ../.env.
.venv/bin/python benchmarks/rerank/benchmark.py --models jina --env-file ../.env \
  --execute --allow-remote --concurrency 40 \
  --repetitions 128 --max-remote-calls 384 \
  --output /tmp/rerank-jina-c40
```

Repeat separately with 16, 24, 32, 40, and 48, using different output paths.
Increase `--max-remote-calls` alongside repetitions: it is a global budget
across models, and failed attempts count against it. Discovery calls used to
check gateway aliases are separate from the inference budget.

Parallel runs print in-flight SDK calls, their peak, completed calls, and
request failures once per second and at completion. `summary.csv` also records
requested concurrency, peak in-flight calls, attempts, failed calls, elapsed
seconds, and successful calls per second. `errors.csv` preserves exception
types and HTTP status codes when the SDK supplies them, without raw exception
messages. Successful responses retain ranking validation and quality metrics;
evaluation errors are marked separately from SDK request errors.

The runner uses a bounded thread pool around the existing synchronous LiteLLM
adapter. Peak in-flight counts measure outstanding client SDK calls, including
transport waits and server queues; they do not count simultaneous GPU model
executions. Throughput includes response evaluation overhead. SDK retries and
application fallback are disabled, while gateway retries, fallback, and caching
remain external. Use the same alias, key limits, and document sizes when
comparing results with another tester. The default fixture is synthetic and
small; choose `--dataset` for representative labeled candidates.

Parallel mode supports remote models only. Local CrossEncoder inference uses
an instance lock, so local comparison runs must use the default concurrency 1.
Coordinate load on a shared gateway and allow cooldown between runs to avoid
confusing cumulative RPM/TPM limits with a concurrency threshold.

Unit tests use fake rerankers and make no inference calls:

```bash
LITELLM_LOCAL_MODEL_COST_MAP=True PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
  .venv/bin/python -B -m pytest -q -p no:cacheprovider \
  benchmarks/rerank/test_benchmark.py benchmarks/rerank/test_load_test.py
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
`jina-rerank` routing alias in its authenticated `/v1/models` response;
otherwise Jina initialization fails before inference. Authenticated model discovery
on 2026-11-16 confirmed `jina-rerank`, `jlu/jina-rerank`, and
`jinaai/jina-reranker-v3.5` are visible. This discovery did not run inference.
Use the routing group `jina-rerank` for normal runs, not a concrete deployment/model ID.
The gateway operator describes a 2:1 priority toward the IxDLab model with JLU
fallback; HAWKI-RAG does not independently verify or configure that gateway policy.
Production bridge composition applies one retryable-error fallback attempt only
when `RERANKER_FALLBACK_MODEL` explicitly names another `litellm_proxy/` alias on
the same gateway/key — not for authentication or configuration errors. Unsetting
it (or `none`) disables the attempt. Benchmark clients do not apply any
production fallback, so individual model latency/quality measurements remain
explicit. See the bridge
README for the production fallback policy and override setting. Neither attempt
protects against gateway-wide outages. Successful synthetic calls to the primary
and explicit JLU aliases on 2026-11-16 verified access, not gateway failover.
The Spark endpoint is an underlying deployment and is not called directly.

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

## Evaluation method and typed boundaries

Install the bridge with `uv sync --frozen --package hawki-bridge`
(add `--extra local-reranker` only for local model inference), then run
`uv sync --frozen --only-group benchmark --inexact` from `python_rag`.
The `benchmark` group belongs to the workspace root, so do not combine
`--package hawki-bridge` with `--group benchmark`. The second sync adds
`ir-measures==0.4.3` only to development/benchmark dependencies, never production.
The reusable clients live in `packages/reranking` (`hawki_reranking`); bridge hit
extraction and production score adaptation remain in `hawki_bridge`.

The pipeline is query IDs → fixed candidate IDs/text → qrels → model runs →
`ir_measures` → comparative JSON/CSV reports. Existing dataset schemas are retained:
synthetic queries' `relevance` dictionaries become qrels; complete private pools'
`id`/`relevance` pairs become qrels. Pending judgments never become zero labels.
`qrels.json` exports original grades. `runs.json` exports one conventional
query/document/score mapping per model and repetition. Rank-derived descending
scores encode the already established stable provider ordering, avoiding changes
from a library's score-tie policy. Candidate identities and raw model scores remain
in existing `rankings` report fields. Incomplete model/query coverage must be checked
before comparison; compare identical fully covered query sets, pools, and qrels.

Standard metric formulas now use [ir_measures](https://ir-measur.es/en/latest/):
`nDCG@10`, `RR@10`, and `R@10`. Original exponential gains (`2**grade-1`) are
passed as transformed qrels **only for nDCG**; binary relevance remains grade > 0.
The private evaluator also reports nDCG@5. Empty runs/no relevant judgments yield
zero. Duplicate query IDs and run document IDs are rejected before export. Recall is recall **within the judged
candidate pool**, not corpus recall. Existing JSON/CSV metric names stay unchanged.

`EvaluationMetrics` is a validated Pydantic model with named attributes and legacy
serialization aliases. `RankedResult` validates candidate index, identity, and finite
score after consuming the native LiteLLM `RerankResponse`; it does not replace that
transport model. Report serialization explicitly uses `model_dump()`.

We selected ir_measures' conventional qrels/run API over direct pytrec_eval
(source-build-oriented upstream distribution) and ranx (Numba/vectorization and
comparison/fusion features beyond this runner's needs). The selected pinned
ir_measures/pytrec-eval-terrier backend is tested on repository Python 3.13.14.
See [metric definitions](https://ir-measur.es/en/latest/measures.html),
[pytrec_eval](https://pypi.org/project/pytrec-eval/), and
[ranx](https://pypi.org/project/ranx/). No custom DCG/MRR/recall formulas remain.

Latency remains separate from relevance evaluation. Synthetic runs have **no excluded
warmup** and include every requested repetition, so their first inference may be cold.
Private evaluation requires at least one excluded warmup and 5–10 measured
repetitions. Initialization is separate; P50/P95 use successful measured calls with
linear interpolation. Warmups/failures consume the explicit remote budget.
The synthetic fixture is for development; private candidate-pool evaluation requires
independent manual judgments and never implies corpus-wide retrieval quality.
