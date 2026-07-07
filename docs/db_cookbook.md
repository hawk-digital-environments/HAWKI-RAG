# DB and Temporal Cookbook

Quick command reference for the HAWKI RAG local stack after the Temporal migration.

## Quick Status

```bash
docker compose ps
docker compose ps postgres temporal hawki_rag_app qdrant hawki_rag_neo4j
docker compose ps hawki-rag-temporal-workflow-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker
docker compose --profile devtools ps temporal-ui
```

## PostgreSQL Access

Use the credentials from `.env`.

```bash
docker compose exec postgres psql -U "$DB_USERNAME" -d "$DB_DATABASE"
docker compose exec postgres psql -U "$DB_USERNAME" -d temporal
```

Laravel app tables live in the app database, default `hawki_rag`. Temporal workflow
history/state/timers/schedules live in the Temporal database and must not be written by
Laravel code.

## Laravel Migrations

```bash
docker compose exec hawki_rag_app php artisan migrate --force
docker compose exec hawki_rag_app php artisan migrate:status
```

Host-shell note: inside Docker, Laravel uses `DB_HOST=postgres`. If you run Artisan from
the host, use a host-reachable PostgreSQL address and port.

## Temporal

Temporal frontend is exposed on `7233`. Temporal UI is optional and, when
started with the `devtools` profile, is exposed through `TEMPORAL_UI_PORT`,
default `8081`.

```bash
docker compose exec temporal-admin-tools temporal workflow list --namespace "${TEMPORAL_NAMESPACE:-default}"
docker compose exec temporal-admin-tools temporal schedule list --namespace "${TEMPORAL_NAMESPACE:-default}"
```

Expected task queues:

- `rag-workflow-task-queue`
- `rag-converter-task-queue`
- `rag-ingestion-task-queue`

## Workers

```bash
docker compose logs -f hawki-rag-temporal-workflow-worker
docker compose logs -f hawki-rag-temporal-converter-worker
docker compose logs -f hawki-rag-temporal-ingestion-worker
```

Each worker can be restarted independently; Temporal will keep workflow state and retry
or resume activities according to workflow/activity policy.

## App Metadata Checks

```sql
SELECT source_id, source_url, index_status, temporal_workflow_id, temporal_schedule_id, updated_at
FROM ingestion_sources
ORDER BY updated_at DESC
LIMIT 20;

SELECT task_id, job_id, job_type, status, current_stage, temporal_workflow_id, temporal_run_id, updated_at
FROM pipeline_jobs
ORDER BY updated_at DESC
LIMIT 20;

SELECT task_id, status, counters, updated_at
FROM pipeline_tasks
ORDER BY updated_at DESC
LIMIT 20;
```

## Shared Storage Checks

Local development uses the shared Docker volume mounted at `/shared`.

```bash
docker compose exec hawki_rag_app ls -la /shared/sources
docker compose exec hawki_rag_app find /shared/sources -maxdepth 3 -type d | sort
```

Expected source handoff paths:

- `/shared/sources/{source_id}/raw/`
- `/shared/sources/{source_id}/markdown/`
- `/shared/sources/{source_id}/ingest/manifest.json`

## Failed Jobs

```sql
SELECT task_id, job_id, source_id, status, error_message, temporal_workflow_id, updated_at
FROM pipeline_jobs
WHERE status = 'failed'
ORDER BY updated_at DESC
LIMIT 50;
```

Retry from Laravel:

```bash
docker compose exec hawki_rag_app php artisan pipeline:retry-failed-jobs TASK_ID
```
