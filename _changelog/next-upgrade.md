# Upgrade to v%%VERSION%%

[//]: # (Add administrator actions only when an upgrade requires manual migration, configuration, environment, or operational changes.)

## 1. Dataset embedding migrations were squashed — a database reset is required

This version removes the migrations `2026_07_14_010000_add_embedding_model_to_datasets_table.php`
and `2026_07_15_010000_add_embedding_provider_to_datasets_table.php`. Their columns now live in
`2026_06_05_000000_create_datasets_table.php`, which pins every dataset to the embedding
provider configured at install time (`RAG_DEFAULT_PROVIDER`, default `ollama`).

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
