# Compose addons

The base `docker-compose.yml` assumes an **external LiteLLM gateway**: with
`RAG_PROVIDER=litellm`, all model calls go to `LITELLM_API_URL` from `.env`
(no local gateway container). Optional services are **additive addon files**
in this directory — inclusion is activation, no profiles, no commented blocks
in the base file. Every addon is self-contained: it carries its own
environment anchor and everything it needs.

## Copy-paste composition

```bash
# Base stack (external gateway assumed via .env):
docker compose -f docker-compose.yml up -d

# Gateway-only stack, the Makefile way (no Ollama container):
make up-core WITH_OLLAMA=0

# Base + local Ollama server (default in make up-core):
docker compose -f docker-compose.yml -f compose/with_ollama.yml up -d

# Base + local LiteLLM gateway (self-hosted proxy instead of external):
docker compose -f docker-compose.yml -f compose/with_litellm.yml up -d

# Base + Temporal workflow UI at http://127.0.0.1:8081:
docker compose -f docker-compose.yml -f compose/with_temporal_ui.yml up -d
```

Multiple addons chain naturally:

```bash
docker compose -f docker-compose.yml -f compose/with_ollama.yml \
  -f compose/with_temporal_ui.yml up -d
```

## The addons

| File | Adds | Make shortcuts | Environment block |
|---|---|---|---|
| `with_ollama.yml` | Local Ollama server; restores the bridge/indexer `depends_on` health gates | `make up-core` (default, `WITH_OLLAMA=1`), `make pull-models` | `Compose Addon: local Ollama server` in `.env.example` |
| `with_ollama_and_gpu.yml` | All GPU acceleration: CUDA builds for reranker/indexer + NVIDIA reservations for Ollama and the bridge; **requires `with_ollama.yml`** | automatic on GPU hosts (`USE_OLLAMA_GPU=1`) | — |
| `with_litellm.yml` | Self-hosted LiteLLM proxy gateway on `127.0.0.1:4000` | `make up-gateway` / `make down-gateway` | `Compose Addon: local LiteLLM gateway` |
| `with_temporal_ui.yml` | Temporal workflow UI | `make up-monitor-workflows-tool` / `down-…` | `Compose Addon: Temporal workflow UI` |

The variables each addon consumes live in matching, clearly-marked blocks in
`.env.example` — configure them there, next to their activation commands.

## Ordering rules

- GPU acceleration is one addon and **requires the Ollama addon**: include in
  this order — base → `with_ollama.yml` → `with_ollama_and_gpu.yml`. The make
  targets do this automatically on GPU hosts; the exact default list is
  `docker-compose.yml:compose/with_ollama.yml:compose/with_ollama_and_gpu.yml:docker-compose.ui.yml`.
  `make up-core WITH_OLLAMA=0` on a GPU host fails loudly (use
  `USE_OLLAMA_GPU=0` too if you really want no GPU): gateway-only stacks have
  no supported GPU composition.
- Addons always compose **with the base file**; they are not standalone.

## Choosing where models run

The model provider is chosen per model string in `.env`, independently of
which containers run:

- **Bare alias** (`EMBED_MODEL=BAAI/bge-m3`): routes to the external gateway
  (`LITELLM_API_URL`).
- **`ollama/…` / `ollama_chat/…` prefix**: routes to the local Ollama addon
  server (`OLLAMA_API_URL`) — even with `RAG_PROVIDER=litellm`, so mixed
  setups (gateway embeddings + local chat/vision) are one-file changes.

Fully local inference: apply the deltas in `.env.example.ollama`. Fully
gateway: `make up-core WITH_OLLAMA=0` with gateway aliases for every model.
Self-hosted proxy: start `with_litellm.yml` and point `LITELLM_API_URL` at
`http://litellm:4000/v1`.

> **Note for existing collections:** switching the *embedding* model source
> (e.g. ollama-served to gateway-served bge-m3) keeps the dimension (1024)
> but can shift vectors between serving stacks — re-embed datasets after
> switching to keep search quality.

## Production

These addons belong to the root/development composition only. The production
composition (`_docker_production/`) mirrors this directory with its own
additive `docker-compose.with-*.yml` overrides — a local Ollama server (with
its production entrypoint), GPU acceleration, a LiteLLM gateway configured
through a static config file instead of the startup renderer, and the
Temporal UI — plus the optional scraper override. Its base stack likewise
assumes the external gateway.
