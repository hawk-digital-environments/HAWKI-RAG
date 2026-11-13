# Retrieval candidate evaluation

This workflow captures production retrieval candidates before reranking, then
evaluates a fixed snapshot locally or through the explicitly approved IxDLab gateway.
The old `dataset.json` remains a synthetic test fixture; it is not a real evaluation set.
`queries.json` contains 40 proposed English/German queries across eight topics.
Review them against the actual corpus; they are not observed user traffic or labels.

## Obtain an authorized request

Use a development application and an account already authorized for its dataset.
No suitable saved authorized request was found during implementation; no live
retrieval was executed. Fixture IDs in tests are not evidence of dataset access.

The current authenticated application flow is:

1. `routes/api.php:61` applies `browser-query-principal` to `/api/query/datasets`
   and `/api/query`. The dataset-list endpoint returns datasets the current user
   can query; use the application's dataset selector rather than guessing IDs.
2. `app/Http/Requests/Rag/QueryDatasetRequest.php:31` checks query-principal access
   and rejects caller-controlled storage scope fields.
3. `app/Http/Controllers/API/HawkiRagProxyController.php:16` passes the authenticated
   user and validated input to `RagProxyService::query()`.
4. `app/Services/Rag/RagProxyService.php:31` calls
   `DatasetQueryAuthorizationService::authorize()`, which checks active grants and
   readiness, then derives collection, embedding model/provider, and graph namespace
   from the authorized dataset. Graph inclusion also depends on readiness and fast mode.
5. `RagProxyService.php:33–57` constructs the internal bridge payload and posts it
   to `/query`. `RagSearchPayloadFactory.php:19` is the corresponding builder used
   by the separate `RagSearcher` flow.

To save the internal payload without editing production code:

1. Attach your existing PHP debugger to the development app. Set a breakpoint in
   `RagProxyService::query()` immediately before the outbound HTTP call (line 54).
2. In the authenticated app, select an available dataset and submit a representative
   query with generation disabled. For graph examples, request `fast_mode=false`;
   Laravel still decides whether graph retrieval is authorized and ready.
3. At the breakpoint, copy only `json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)`
   as JSON into `/tmp/hawki-authorized-request.json`. Save the object, not a quoted
   debugger string. Do not copy cookies, bearer tokens, headers, or credentials.
4. Stop that debug request before the outbound call, so capturing it does not invoke
   retrieval, reranking, or answer generation. Remove the breakpoint afterwards.

The browser's `/api/query` body does **not** contain the derived scope, and neither
the dataset-list response nor a test fixture is a substitute for the internal payload.
If a debugger is unavailable, have the development operator export that payload;
do not reconstruct storage targets or extract credentials from logs.

The saved JSON is an operator input, not a signed access token. The collector
cannot reauthenticate a browser session or renew grants. Keep it private, use only
an authorized development dataset, and recapture it when permissions/configuration change.

Examples of the correct flow (with artificial fixtures):

- `tests/Feature/Query/DatasetScopedQueryTest.php:20`
- `tests/Feature/Query/GraphRetrievalModeTest.php:38`
- `python_rag/services/hawki_bridge/tests/contract/http/test_query_api.py:193`

## Capture candidates

Run from `python_rag` in the existing bridge environment with configured access
to its development Qdrant, Neo4j, and local Ollama services. Do not start services
or substitute other collections to make a request work. This initial collector
supports saved requests using the authorized local Ollama embedding route only.

```bash
# Validate the saved request and plan only; no service calls.
.venv/bin/python -B benchmarks/rerank/collect.py \
  --request /tmp/hawki-authorized-request.json \
  --output benchmarks/rerank/collected/candidates.json

# Only after the development request and service configuration are authorized:
.venv/bin/python -B benchmarks/rerank/collect.py \
  --request /tmp/hawki-authorized-request.json \
  --limit 50 --execute \
  --output benchmarks/rerank/collected/candidates.json
```

The collector reuses `build_query_runtime()`, `prepare_query()`, and
`retrieve_query_hits()` in `hawki_bridge/application/query/execution.py`.
The boundary is the return from `retrieve_query_hits()` (line 307), consumed by
`expand_query_hits()` before its first `rerank_and_filter_hits()` call (line 403).
It does not perform iterative expansion, final filtering, reranking, or generation.
Vector, lexical, and structural fusion use the existing production functions.

Capture retains the first 20–50 requested candidates in fused retrieval order,
without padding if fewer exist. `below_target` identifies pools smaller than 20.
Production retrieval may include relation evidence as well as chunks; component
type is preserved in metadata. Graph outages can degrade to fewer graph results;
check service availability and snapshot coverage before treating graph examples as representative.

Each candidate preserves its original point ID, stable identity, metadata (including
document/chunk IDs), fused retrieval score, zero-based order, and the exact sanitized
text sent to rerankers. Text uses the existing 2,000-character extraction limit;
sanitization is not anonymization. Never commit private snapshots or reports.
`collected/` and `evaluation-results/` are ignored; capture refuses to overwrite a file.

## Label the fixed pools

Edit only `candidates[].relevance` and each query's `judgment_status`:

```json
{"id": "point:original-id", "text": "captured text", "relevance": null}
```

Use 0 = irrelevant, 1 = useful background, 2 = partially answers, 3 = directly
answers the query. Start with null, not zero. Judge the captured reranking text
against `rerank_query`; use the metadata to inspect the original source as needed.
Mark `judgment_status` as `complete` only after every candidate has a grade.
Pending/partially judged queries receive latency measurements but **no quality metrics**.
Do not auto-label with retrieval scores or another reranker. Include enough
German/English source content and review the proposed queries for actual corpus coverage.

## Evaluate

```bash
# Offline plan and validation; no inference or downloads.
.venv/bin/python -B benchmarks/rerank/evaluate.py \
  --dataset benchmarks/rerank/collected/candidates.json

# Local models, one excluded warmup each, five timed repetitions per query.
.venv/bin/python -B benchmarks/rerank/evaluate.py \
  --dataset benchmarks/rerank/collected/candidates.json \
  --models local bge --execute --repetitions 5

# Explicitly authorize private text ONLY to IxDLab; 40 queries × 5 + 1 warmup = 201 calls.
.venv/bin/python -B benchmarks/rerank/evaluate.py \
  --dataset benchmarks/rerank/collected/candidates.json \
  --models local bge jina --execute --allow-gateway \
  --env-file ../.env --repetitions 5 --max-remote-calls 201
```

No real evaluation was run during implementation. Local weights must already be
cached; this evaluator does not offer downloads. Direct external providers are
rejected even if their keys exist. The existing model factory restricts gateway
traffic to `https://ai.ixdlab.hawk.de` and verifies the Jina alias before inference.
Warmup and failed remote requests count against the cap; default cap is zero.
Warmup failure prevents measurement for that model. There are no automatic retries.

Results use the existing JSON/CSV exports under `evaluation-results/`. They contain
document IDs/rankings but no candidate text; treat IDs as private too. Reports add
a snapshot SHA-256, per-call candidate counts/text lengths, query coverage,
judged-query coverage, measured failure rates, and P50/P95 excluding initialization
and warmups. Warmup outcomes are in `results.json`; measurement failures are in `errors.csv`.
Use `--output` to keep separate runs; exports overwrite their existing output files.

nDCG@5/@10 uses grades 0–3; MRR@10 treats grades > 0 as relevant. Recall@10 is
relative to the completely judged **candidate pool**, not all relevant corpus
documents. Quality averages successful labeled calls; compare query coverage and
failure rates before comparing models. Use 5–10 repetitions and at least one warmup.

```bash
LITELLM_LOCAL_MODEL_COST_MAP=True PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
  .venv/bin/python -B -m pytest -q -p no:cacheprovider \
  benchmarks/rerank/test_evaluation.py benchmarks/rerank/test_benchmark.py
```
