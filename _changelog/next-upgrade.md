# Upgrade to v%%VERSION%%

[//]: # (Add administrator actions only when an upgrade requires manual migration, configuration, environment, or operational changes.)

## 1. Dataset embedding migrations were squashed — a database reset is required

This version removes the migrations `2026_07_14_010000_add_embedding_model_to_datasets_table.php`
and `2026_07_15_010000_add_embedding_provider_to_datasets_table.php`. Their columns now live in
`2026_06_05_000000_create_datasets_table.php`, which pins every dataset to the embedding
provider configured at install time (`RAG_PROVIDER`).

Databases migrated with v1.5.0 reference the deleted files by name, so an in-place
`php artisan migrate` will fail. Two supported paths:

| Situation | Path |
|---|---|
| No data worth keeping (dev, evaluation) | `php artisan migrate:fresh --force` inside `hawki_rag_app`, then `make up-core` |
| Data must be retained | Back up first (see the v1.5.0 upgrade guide), `migrate:fresh`, then restore application rows and re-ingest; Qdrant/Neo4j volumes stay intact if the embedding model is unchanged |

`migrate:fresh` drops all Laravel tables and re-runs the squashed chain (21 migrations).
Temporal persistence (PostgreSQL `temporal`/`temporal_visibility` databases) is unaffected.

## 2. Reconcile `.env`: renamed Temporal UI variables

`TEMPORAL_UI_BIND_ADDRESS` and `TEMPORAL_UI_PORT` were renamed because the
`temporalio/ui` container interpreted them as its internal listen port, which broke
the host port mapping:

| Old | New |
|---|---|
| `TEMPORAL_UI_BIND_ADDRESS` | `TEMPORAL_UI_HOST_BIND` |
| `TEMPORAL_UI_PORT` | `TEMPORAL_UI_HOST_PORT` |

Both remain host-side only (defaults `127.0.0.1` and `8081`); the container always
listens internally on 8080.

## 3. Enable automatic dataset cleanup

Rebuild and restart the Laravel app image. Its existing Supervisor now runs
`php artisan schedule:work`; external Laravel deployments must run the standard
scheduler every minute. `php artisan schedule:list` should show `dataset-cleanup`.

Archived datasets are treated as pending deletions and retried automatically.
Verified cleanup retains their rows with status `deleted`, preventing reuse of
dataset identities. Keep source, writer and operation evidence for recovery.
No database migration or additional queue service is required for this change.

## 4. Canonical model keys replace the per-provider model keys

The model layer now reads **one canonical set of provider-prefixed keys** whose
prefix routes the request; the LiteLLM Python SDK client is the single
implementation behind both public provider names (`ollama`, `litellm`):

| Key | Example |
|---|---|
| `EMBED_MODEL` | `ollama/bge-m3` |
| `CHAT_MODEL` | `ollama_chat/llama3.1:8b` |
| `VISION_MODEL` | `ollama_chat/qwen2.5vl:7b` |
| `EMBED_MODELS` / `CHAT_MODELS` / `VISION_MODELS` | optional Settings-UI allowlists |

Provider selection moves to a single required `RAG_PROVIDER` key
(`ollama` or `litellm`); `RAG_DEFAULT_PROVIDER` and `GRAPH_PROVIDER` are
gone, and a missing provider fails loud instead of silently defaulting to
Ollama. Set it explicitly in `.env`.
Model values are provider-prefixed strings; bare names only work where they
are real model names on the target backend (dataset-pinned `bge-m3` on direct
Ollama keeps working: the client prefixes unprefixed names per provider).

**Actions:**

1. Add the three canonical keys to `.env`.
2. Remove the retired keys from `.env`:

   | Removed | Replacement |
   |---|---|
   | `OLLAMA_EMBED_MODEL`, `OLLAMA_EMBED_MODELS` | `EMBED_MODEL`, `EMBED_MODELS` |
   | `OLLAMA_RAG_MODEL`, `OLLAMA_CHAT_MODELS` | `CHAT_MODEL`, `CHAT_MODELS` |
   | `OLLAMA_VISION_MODEL`, `OLLAMA_VISION_MODELS` | `VISION_MODEL`, `VISION_MODELS` |
   | `GRAPH_OLLAMA_RAG_MODEL`, `HAWKI_RAG_GRAPH_MODEL`, `HAWKI_RAG_GRAPH_MODELS` | `GRAPH_RAG_MODEL` (optional; defaults to `CHAT_MODEL`) |
   | `GRAPH_OLLAMA_VISION_MODEL` | `VISION_MODEL` |
   | `LITELLM_CHAT_MODEL`, `LITELLM_EMBED_MODEL`, `LITELLM_VISION_MODEL` | `CHAT_MODEL`, `EMBED_MODEL`, `VISION_MODEL` |
   | `LITELLM_CHAT_ALIASES`, `LITELLM_EMBED_ALIASES`, `LITELLM_VISION_ALIASES` | `CHAT_MODELS`, `EMBED_MODELS`, `VISION_MODELS` |
   | `LITELLM_OLLAMA_API_BASE`, `LITELLM_OLLAMA_CHAT_MODEL`, `LITELLM_OLLAMA_EMBED_MODEL`, `LITELLM_OLLAMA_VISION_MODEL` | `OLLAMA_API_URL` and the canonical keys; the gateway renders its routes from them at container startup |
   | `LITELLM_OPENAI_API_BASE`, `LITELLM_OPENAI_CHAT_MODEL`, `LITELLM_OPENAI_EMBED_MODEL`, `LITELLM_OPENAI_VISION_MODEL` | First `openai/` entry in `CHAT_MODELS` / `EMBED_MODELS` / `VISION_MODELS`; optional standard `OPENAI_API_BASE` override |
   | `LITELLM_ANTHROPIC_API_BASE`, `LITELLM_ANTHROPIC_CHAT_MODEL`, `LITELLM_ANTHROPIC_VISION_MODEL` | First `anthropic/` entry in `CHAT_MODELS` / `VISION_MODELS`; optional standard `ANTHROPIC_API_BASE` override |
   | `RAG_DEFAULT_PROVIDER`, `GRAPH_PROVIDER` | `RAG_PROVIDER` (required, no default) |

   The gateway's static `docker/litellm/config.yaml` is gone: a startup
   renderer derives the proxy's `model_list` from the canonical keys and the
   allowlist plurals, so the Settings UI menu and the gateway routes cannot
   drift apart. The retired `hawki-*` proxy aliases (`hawki-chat`,
   `hawki-ollama-*`, `hawki-gpt-*`, `hawki-claude-*`) are **gone**: every
   gateway route is named by a canonical provider-prefixed model. Re-select
   models in Settings — stored selections containing aliases are rejected by
   validation. `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` keep their standard
   names. Dataset rows cannot hold aliases after this upgrade because §1
   already requires `migrate:fresh`.
3. No additional database migration for the model keys: the §1 reset
   re-creates datasets, and they pin canonical values from the new config.

**Executed cleanups (nothing pending):**

- The hand-rolled `OllamaProvider` and `LiteLLMProvider` clients were removed;
  their battle-tested hygiene (prompt cleaning, embedding-dimension inference,
  the NaN zero-vector fallback, chat retry knobs) moved into the SDK client and
  `hawki_model_providers.helpers`.
- The `MODEL_PROVIDER_CLIENT` implementation switch was removed together with
  the native clients — there is exactly one implementation.
- `config/model_providers.php` and `config/config.php` source defaults and
  allowlists from the canonical keys.
- Verified against live Ollama: `/api/embed` (SDK route) and the former
  `/api/embeddings` route return directionally identical vectors (cosine 1.0;
  the legacy endpoint returns unnormalized vectors, the new one normalizes —
  irrelevant under `QDRANT_DISTANCE=Cosine`). Collections must not use the
  `Dot` metric when mixing pre/post-migration vectors.
- The legacy `/api/generate` chat fallback is gone with the native client;
  the SDK client speaks `/api/chat` directly.

