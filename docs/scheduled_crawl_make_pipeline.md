# Scheduled Crawl Make Pipeline

This scheduler integration is additive. It does not replace crawler or ingest internals.
It validates and calls the existing Make commands.

## Environment Variables

Set in root `.env`:

```dotenv
SCHEDULER_PIPELINE_MODE=make-sync

SCRAPER_REPO_PATH=/absolute/path/to/RAWKI
RAG_REPO_PATH=/absolute/path/to/RAWKI

SCRAPER_MAKE_TARGET=crawl
RAG_MAKE_TARGET=ingest

DEFAULT_CRAWLED_ROOT=/app/shared/crawled-data
DEFAULT_SITEMAP_PAGES=100
DEFAULT_MAX_PAGES_FULL=
DEFAULT_RESCRAPE_FAILED=false
DEFAULT_SKIP_IMAGES=true

PIPELINE_CHECK_BEFORE_RUN=true
PIPELINE_COMMAND_TIMEOUT_SECONDS=3600
PIPELINE_DRY_CHECK_TIMEOUT_SECONDS=30
```

## Commands

Create scheduled job:

```bash
php artisan rag:schedule-crawl \
  --url="https://www.hawk.de" \
  --period=per-day \
  --job-id="job_date_2026_04_28" \
  --crawled-root="/app/shared/crawled-data" \
  --graph=true
```

Run due jobs:

```bash
php artisan rag:run-scheduled-crawls
```

Run through the project Makefile:

```bash
make scheduled-crawls
```

The Make target runs Artisan from the host with `DB_HOST=127.0.0.1` and host repo paths so the scheduled pipeline can call Docker/Make.

Cron should call Laravel's scheduler once per minute:

```cron
* * * * * cd /absolute/path/to/HAWKI-RAG && make scheduler-run >> /dev/null 2>&1
```

## Pipeline Modes

- `make-sync`: runs `make crawl`, then `make ingest` against the crawl output directory.
- `rabbitmq-event`: runs `make crawl` only. Downstream is event-driven.
- `make`: alias of `make-sync` for backward compatibility.

## Prechecks

When `PIPELINE_CHECK_BEFORE_RUN=true`, scheduler validates:

- scraper repo path + Makefile + `crawl` target; this may be the RAWKI repo when using the local Makefile targets
- RAG repo path + Makefile + `ingest` target
- `docker compose ps` works in scraper repo
- crawler service exists in compose services when the scraper Makefile is external
- `docker exec hawki_rag_bridge true` works
- URL, JOB_ID_FULL are non-empty
- CRAWLED_ROOT is not placeholder path
- dry-run checks via `make -n ...`

If a check fails, run is marked `failed_precheck` and execution is skipped.

## Run Statuses

- `pending`
- `prechecking`
- `failed_precheck`
- `running_scraper`
- `scraper_failed`
- `running_ingest`
- `ingest_failed`
- `dispatched`
- `completed`
- `failed`
