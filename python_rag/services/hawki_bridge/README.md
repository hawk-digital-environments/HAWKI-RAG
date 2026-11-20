# HAWKI bridge

`hawki_bridge` provides Laravel-authorized query/graph-read APIs, health, and
Temporal control operations. It does not provide canonical Qdrant/Neo4j ingestion
writes or resolve the caller's dataset grants.

The handbook owns the full [architecture](../../../_documentation/Getting%20Started/3_introduction_architecture.md),
[retrieval flow](../../../_documentation/Core%20Concepts/query_retrieval.md),
[authorization boundary](../../../_documentation/Core%20Concepts/authorization_dataset_scope.md),
and [HTTP contracts](../../../_documentation/Reference/rest_apis.md).

## Entrypoint

[src/hawki_bridge/main.py](src/hawki_bridge/main.py) exports the ASGI application
`hawki_bridge.main:app`. The `hawki-bridge` console command declared in
[pyproject.toml](pyproject.toml) starts it on port 8000 for local execution.
Use the handbook's [workspace setup](../../../_documentation/Developer/testing.md#python-workspace)
before running local commands; container startup belongs to
[Run HAWKI RAG](../../../_documentation/Getting%20Started/2_setup.md).

## Tests

Tests live in [tests/](tests/). From `python_rag`, run:

```bash
uv run --group test --package hawki-bridge pytest services/hawki_bridge/tests
```

The `integration/` category requires live Qdrant. See
[Testing](../../../_documentation/Developer/testing.md) for locked environment
commands and infrastructure requirements.

## Production reranking backends

`RERANKER_MODE` retains its existing `none`, `cosine`, `external`, and `jina`
semantics. The bridge default remains `none`; the stack example remains
`external`. Explicit request values override the mode and blending defaults.
`RERANKER_PROVIDER` selects the implementation of **external mode**:

- `cohere` (default): the existing HTTP client at `RERANKER_API_URL`, with optional
  `RERANKER_API_KEY`. This compatibility name does not enable hosted Cohere SDK calls.
- `local`: a reusable, lazily loaded in-process `LocalReranker`.
- `litellm`: a reusable `LiteLLMReranker` calling the Python SDK.

The shared `hawki_reranking` package owns these clients. The bridge binds them to
`RerankHitsPort` and retains hit mapping, normalization/blending, and atomic
fallback to original retrieval hits. The standalone `hawki_reranker` HTTP service
remains the legacy backend.

This also applies to internal callers explicitly requesting `external`, so
`RERANKER_MODE=none` is not a global kill switch.
Cosine and direct Jina modes retain their existing implementations; reranking
never changes the dataset's embedding provider or model.

Legacy HTTP (the existing stack default):

```dotenv
RERANKER_MODE=external
RERANKER_PROVIDER=cohere
RERANKER_API_URL=http://hawki_rag_rerank/v1/rerank
```

Mixedbread:

```dotenv
RERANKER_MODE=external
RERANKER_PROVIDER=local
HAWKI_RERANKER_MODEL=mixedbread-ai/mxbai-rerank-base-v1
```

For BGE, use `HAWKI_RERANKER_MODEL=BAAI/bge-reranker-v2-m3` instead.
The existing standalone reranker also uses `HAWKI_RERANKER_MODEL`.

IxDLab Jina through the existing gateway:

```dotenv
RERANKER_MODE=external
RERANKER_PROVIDER=litellm
RERANKER_MODEL=litellm_proxy/jina-rerank
LITELLM_PROXY_API_BASE=https://ai.ixdlab.hawk.de
LITELLM_PROXY_API_KEY=<existing gateway key>
```

Other SDK providers use their provider-prefixed model (for example,
`RERANKER_MODEL=cohere/rerank-v3.5`) and normal LiteLLM provider credentials
(for example, `COHERE_API_KEY`). Gateway base/key options are passed only for
`litellm_proxy/` models, and the model must carry a rerank-capable provider
prefix: unknown prefixes are rejected during composition instead of failing
per query. SDK calls use a 30-second timeout and zero retries.
No new proxy or service is needed. Missing SDK model/gateway configuration is
rejected during composition; runtime inference or response failures retain the
original reranking input without logging raw SDK exceptions.

`rerank_top_n` bounds the candidate prefix, not the final response count. The
client requests one score per prefix candidate and validates all original indices
before applying existing normalization/blending. The tail remains untouched by
reranking; existing lexical filtering and context limits still apply afterwards.
Documents use the shared sanitized extractor without a character cutoff. Model
context limits and truncation remain the model/provider's responsibility.

### Optional local bridge image

The default bridge image excludes local model dependencies. Build the existing
bridge target with the optional extra and OpenMP runtime:

```bash
docker build -f docker/python_rag.Dockerfile --target bridge-runtime \
  --build-arg BRIDGE_LOCAL_RERANKER=true -t hawki-rag-bridge:local-reranker .

# GPU variant (CUDA torch build):
docker build -f docker/python_rag.Dockerfile --target bridge-runtime \
  --build-arg BRIDGE_LOCAL_RERANKER=true --build-arg TORCH_VARIANT=gpu \
  -t hawki-rag-bridge:local-reranker-gpu .
```

Run from the repository root. Configure the existing bridge deployment to use
this image; no additional service is required. Provision a writable Hugging Face
cache and cache the selected weights before serving queries if downloads are
unavailable at runtime. Loading remains lazy and inference is serialized per
instance. CPU/GPU selection follows Sentence Transformers defaults. Deterministic
query tests inject model/SDK doubles and do not download weights.

GPU: build the `-gpu` tag and include the GPU addon
(`compose/with_ollama_and_gpu.yml`) so the bridge container gets the NVIDIA
reservation; Sentence Transformers picks CUDA automatically. The default build
installs the slim CPU-only torch wheel.


### Gateway reranker fallback

`RERANKER_FALLBACK_MODEL` is explicit configuration for `RERANKER_PROVIDER=litellm`
with a `litellm_proxy/` primary: set it to another `litellm_proxy/` alias, and one
retryable primary failure triggers exactly one fallback attempt with the same
input, gateway, and key. The IxDLab deployment mirrors the Jina primary:

```dotenv
RERANKER_FALLBACK_MODEL=litellm_proxy/jlu/jina-rerank
```

- Unset, empty, or `none`: no application fallback.
- Another `litellm_proxy/` model: the fallback on the same gateway/key.
  The primary itself, empty gateway aliases, and fallbacks without a
  `litellm_proxy/` primary are rejected to prevent loops and cross-provider leaks.

The fallback is not derived from the gateway address; every gateway decides its
own alias explicitly. Both initial and expansion ranking use this policy.

Only LiteLLM connection, timeout, rate-limit, internal-server, and service-unavailable
errors qualify. Authentication, authorization, bad requests, missing models,
configuration errors, and malformed ranking responses do not trigger another
inference call. The fallback is attempted once, with the same input, gateway,
virtual key, 30-second per-attempt timeout and zero SDK retries. Thus one reranking
operation makes at most two client inference calls (up to approximately 60 seconds);
an expansion pass is a separate operation. If both fail, the existing bridge
wrapper atomically returns original hits for downstream filtering.

The gateway operator reports that `jina-rerank` already has JLU failover, but model
listing and a successful request do not establish reliable failover behavior.
Gateway configuration/failure telemetry was not available for independent
confirmation. The explicit application fallback is the requested safeguard for
the IxDLab deployment. It may repeat a JLU attempt already made internally by the
gateway. When reliable gateway-managed fallback is confirmed, unset
`RERANKER_FALLBACK_MODEL` (or set it to `none`) to avoid that duplication.
HAWKI-RAG does not configure gateway weights or fallback chains,
and it never calls Spark or JLU directly outside the authorized gateway.
Because both client attempts use the same gateway, this fallback does not protect
against gateway-wide outages.

A single synthetic request to the explicit JLU alias succeeded on 2026-11-16.
This verifies access, not the routing group's failover policy. No induced-failure
live tests were performed. Benchmark clients remain single-model measurements;
the explicit fallback above is applied by production bridge composition.
