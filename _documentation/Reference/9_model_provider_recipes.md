---
title: Model Provider Recipes
---

# Model provider recipes

Ready-made `.env` setups for the three ways HAWKI RAG can be served models:
local Ollama, a self-hosted LiteLLM gateway, or a foreign (external)
LiteLLM/OpenAI-compatible gateway. Each recipe lists exactly which lines to
touch — everything else stays at the template defaults, and the complete
variable reference lives in
[Environment & Configuration](../Operations/5_environment_db_queue.md).

The one rule that matters: with `RAG_PROVIDER=litellm`, **every** model call
(chat, embedding, vision) goes to `LITELLM_API_URL`, and the model string you
configure travels verbatim as the gateway's deployment name. Your model names
must therefore be names the gateway resolves — bare aliases it serves
directly, or `openai/…`/`anthropic/…` for hosted models behind it.

## Recipe 1: local Ollama (the template default)

Everything the stack needs for fully local model serving.

```bash
cd _docker_production
cp .env.example .env
cp .env.ollama.example .env.ollama
./generate-env-secrets.sh   # paste the printed block into .env
```

In `.env`, extend the `COMPOSE_FILE` line at the top:

```bash
COMPOSE_FILE=docker-compose.yml:docker-compose.with-ollama.yml
```

That is all — the core defaults (`RAG_PROVIDER=ollama` with
`ollama/bge-m3`, `ollama_chat/llama3.1:8b`, `ollama_chat/qwen2.5vl:7b`)
and the fragment (`OLLAMA_API_URL`, timeouts, keep-alive, model-pull list)
already match. With GPU acceleration:

```bash
COMPOSE_FILE=docker-compose.yml:docker-compose.with-ollama.yml:docker-compose.with-ollama-and-gpu.yml
```

(The GPU addon must come **after** the Ollama addon.) The entrypoint pulls
`HAWKI_RAG_OLLAMA_MODELS` at container start — trim that list on CPU-only
hosts.

```bash
./deploy.sh
```

## Recipe 2: local LiteLLM gateway (self-hosted proxy)

Models are served through your own LiteLLM container from the
`docker-compose.with-litellm.yml` addon; its routes live in
`litellm-config.yml` next to it.

```bash
cd _docker_production
cp .env.example .env
cp .env.litellm.example .env.litellm
./generate-env-secrets.sh   # paste the printed block into .env
```

In `.env`, set the provider block and model names — each name must match a
route's `model_name` in `litellm-config.yml`:

```bash
COMPOSE_FILE=docker-compose.yml:docker-compose.with-litellm.yml

RAG_PROVIDER=litellm
LITELLM_API_URL=http://litellm:4000/v1
LITELLM_API_KEY=<generate one; current LiteLLM images refuse to boot without a master key>

EMBED_MODEL=openai/text-embedding-3-small
CHAT_MODEL=openai/gpt-4.1-mini
VISION_MODEL=openai/gpt-4.1-mini
GRAPH_EMBEDDING_DIMENSIONS=ollama/bge-m3=1024,openai/text-embedding-3-small=1536
```

In `.env.litellm`, fill the upstream credentials the routes reference
(routes whose key is empty stay listed and fail closed):

```bash
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
```

And add one passthrough route per model in `litellm-config.yml` — the
`model_name` must equal the configured string exactly:

```yaml
model_list:
  - model_name: openai/text-embedding-3-small
    litellm_params:
      model: openai/text-embedding-3-small
      api_key: os.environ/OPENAI_API_KEY
  - model_name: openai/gpt-4.1-mini
    litellm_params:
      model: openai/gpt-4.1-mini
      api_key: os.environ/OPENAI_API_KEY
  # Mixed setup: keep an Ollama model behind the same gateway (requires
  # the with-ollama addon as well, the api_base strips the trailing /api):
  # - model_name: ollama_chat/llama3.1:8b
  #   litellm_params:
  #     model: ollama_chat/llama3.1:8b
  #     api_base: http://hawki_ollama:11434
```

```bash
./deploy.sh
```

## Recipe 3: foreign LiteLLM gateway (external, OpenAI-compatible)

The gateway runs outside the stack (university cluster, corporate AI portal).
No compose addon and no `.env.litellm` fragment — that fragment's
`OPENAI_API_KEY`/`ANTHROPIC_API_KEY` are only read by the *local* addon's
route config. Everything happens in `.env`:

```bash
cd _docker_production
cp .env.example .env          # COMPOSE_FILE stays docker-compose.yml
./generate-env-secrets.sh     # paste the printed block into .env
```

Worked example — an external gateway at `https://ai.domain.eu/v1` serving
`gemma-4` (chat), `baai/bge-m3` (embeddings, 1024 dimensions), and
`jinaai/jina-reranker-v3.5` (reranking):

```bash
# --- model runtime -----------------------------------------------
RAG_PROVIDER=litellm
LITELLM_API_URL=https://ai.domain.eu/v1
LITELLM_API_KEY=<gateway key>

# Bare aliases: the gateway receives them verbatim as deployment names.
EMBED_MODEL=baai/bge-m3
CHAT_MODEL=gemma-4
# Must be a multimodal alias the gateway serves; only invoked for
# image-heavy graph ingestion and warmup.
VISION_MODEL=gemma-4

# Keep the map in sync with the embedding model — graph-only ingestion
# has no vector response to measure the dimension from:
GRAPH_EMBEDDING_DIMENSIONS=ollama/bge-m3=1024,openai/text-embedding-3-small=1536,baai/bge-m3=1024

# --- reranking through the same gateway --------------------------
# LITELLM_PROXY_* is deliberately a separate pair from LITELLM_API_URL/KEY;
# a trailing /v1 in the base is stripped automatically.
RERANKER_MODE=external
RERANKER_PROVIDER=litellm
RERANKER_MODEL=litellm_proxy/jinaai/jina-reranker-v3.5
LITELLM_PROXY_API_BASE=https://ai.domain.eu/v1
LITELLM_PROXY_API_KEY=<gateway key>
```

```bash
./deploy.sh
```

Notes for this recipe:

- Existing datasets keep the embedding model they were created with — a new
  `EMBED_MODEL` applies to newly created datasets (re-ingest or use a new
  collection to migrate; `baai/bge-m3` at 1024 dimensions happens to match
  collections built with `ollama/bge-m3`, which is also 1024).
- The gateway must expose a `/rerank`-capable route for the reranker alias —
  `litellm_proxy/` models are validated at bridge startup, so a misrouted
  alias fails fast instead of per query.
- Optional resilience: `RERANKER_FALLBACK_MODEL=litellm_proxy/<another
  alias>` retries once on retryable errors (same gateway, different alias).
- `GRAPH_EMBEDDING_DIMENSIONS` and the model allowlists
  (`EMBED_MODELS`/`CHAT_MODELS`/`VISION_MODELS`) are worth extending when
  you want more models selectable in the Settings UI.

## Which recipe fits?

| | Ollama | Local LiteLLM | Foreign gateway |
|---|---|---|---|
| Model calls go to | `hawki_ollama:11434` directly | `litellm:4000` in-stack | external `https://…/v1` |
| Extra container | ollama (+ optional GPU) | litellm | none |
| Env fragments | `.env.ollama` | `.env.litellm` | none |
| Upstream keys | none | `OPENAI_API_KEY`/`ANTHROPIC_API_KEY` (per route) | `LITELLM_API_KEY` (+ `LITELLM_PROXY_API_KEY` for reranking) |
| Secrets leave the host | no | only to the local proxy | yes (to the gateway operator) |
