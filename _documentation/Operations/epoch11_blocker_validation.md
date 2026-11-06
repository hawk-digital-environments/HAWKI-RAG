# Epoch 11 remaining-blocker validation — 2026-11-06

Branch: `feat/python-url-crawler-ingestion`. The existing uncommitted implementation
was retained. No commit, push, reset, clean, production selector change, or traffic
migration was performed.

## Result

**BLOCKED by the external crawler runtime contract.** The deployed CustomCrawler
has no verified connection-bound fetch/redirect/sitemap enforcement and no durable
sealed-job drainage evidence. Backend dispatch and scraper activity both fail
closed. Passing deterministic tests does not establish an available safe crawler.
The exact external prerequisite and inspected transport chain are documented in
[the URL runbook](python_url_ingestion.md#concrete-external-runtime-prerequisite).

| Required area | Result | Evidence / limit |
|---|---|---|
| Connection-bound SSRF | EXTERNAL PREREQUISITE | No supported runtime capability; dispatch returns 503 before persistence or remote work |
| Redirect safety | FAIL for usable fetching | No redirect-capable safe transport exists; fail-closed non-dispatch tests pass |
| Sitemap safety | FAIL for usable expansion | Remote fetch/expansion stays disabled; local paths, private/scheme targets and metadata bypasses are rejected |
| DNS rebinding | FAIL for usable fetching | Tests prove no second-resolution fetch can be dispatched; they do not claim working IP pinning |
| External-job quiescence gate | PASS | Persisted generation/job identity, cancellation intent/evidence, permanent admission closure and terminal drainage required before any cleanup |
| Delete quiescence | PASS | Unknown/unreachable/unsealed jobs remain 202; artifacts and sink state remain intact |
| Recrawl quiescence | PASS | No successor dispatch before remote seal/drain and registered writer release; stale receipts affect only their own generation |
| Generation-aware schedules | PASS | Database due-time ticks enter authoritative recrawl; no frozen Temporal input is scheduled |
| Manual/schedule race | PASS | Real PostgreSQL admits one successor; busy/deleting/not-due states skip or replay the same claim |
| Mixed ownership | PASS | Durable generation/revision/text/dispatch evidence plus positive legacy task/job/source evidence |
| Bulk recovery | PASS | Per-ID normalization, authorization, dedupe and owner partition; individual failures cannot fall back to another owner |
| Task control | PASS | Unsupported Python mutations fail closed; proven authorized legacy handlers remain available |
| Callback routing | PASS | Python URL mode classifies before any prefix routing, including text-prefix collisions; owner/receipt collisions reject |
| Status/event/log compatibility | PASS | Authenticated projections and authorized legacy merge; common order and global limit; no mirror rows |
| Previous Epoch 11 guarantees | PASS | Aliases, 201, metadata, grants, locking, identity, idempotency, writer binding, stale fences, headerless delete, 202 mapping, historical sinks, no fallback |
| Epochs 7–10 regression | PASS | Query, workflow control, direct text and managed-document regression suites below |

Managed pipeline recovery reuses the existing managed replacement protocol and
its frozen upload. Two optional internal compare conditions were added to its
claim: expected generation and expected revision. Existing public managed calls
leave both unset. A real PostgreSQL recovery/manual replacement race verifies
that an old failed revision cannot replace a concurrent successor. Direct-text
pipeline retry has no new protocol: it explicitly returns 409 and retains the
existing text mutation APIs. No Laravel dispatch is used for Python-owned work.

## Executed checks

| Check | Result |
|---|---|
| Focused backend URL/security/quiescence/schedule/recovery/callback/read tests, normal and optimized | 119 passed in each mode; 13 PostgreSQL cases skipped here and executed separately |
| Full backend, `pytest tests -m 'not integration' -q` | 1,409 passed, 83 skipped, 5 deselected |
| Optimized backend URL/managed/text/workflow/auth suites | 698 passed, 55 skipped, 1 deselected |
| PostgreSQL Epoch 9–11 suites, normal and optimized | 55 passed in each mode, including all 13 URL cases and the new managed recovery/manual race |
| Scraper/converter/indexer/workflow/callback/contracts/workflow-control suites | 357 passed, 6 live integration cases deselected |
| Same worker suites with `python -O` | 357 passed, 6 deselected |
| Laravel pipeline/scraper/query/auth/principal/text/managed BFF/writer/relevant unit suites | 475 passed, 2,750 assertions |
| Laravel managed deletion, isolated PHPUnit processes | 37 passed, 192 assertions |
| Laravel document browser/unified API, isolated PHPUnit processes | 14 passed, 123 assertions |
| Ruff check and format check, backend plus worker packages/services | Passed; 538 Python files formatted |
| Both `uv lock --check` invocations | Passed; no dependency changes |
| Pint over all changed/new PHP files | Passed |
| Composer validation | Passed |
| `git diff --check` | Passed |

Commands were run using the existing `python_backend/.venv/bin/python`,
`python_rag/.venv/bin/python`, backend-installed Ruff, `php artisan test`,
`php vendor/bin/phpunit`, `vendor/bin/pint`, `composer validate --no-check-publish`
and `uv lock --check --project python_backend|python_rag`.

The full backend/optimized runs required the approved filesystem permissions:
the macOS sandbox strips directory setgid bits in existing Epoch 9 artifact
tests. Those tests passed outside that sandbox without changing their assertions.
Pytest emits its usual `-O` warning; production behavior is also tested normally.
PostgreSQL used isolated `epoch9_blockers_test`, `epoch10_blockers_test`, and
`epoch11_blockers_test` databases on `review_pg` (PostgreSQL 16), without touching
production schemas. Credentials were consumed internally and not printed.

## Live validation classification

| Unexecuted check | Classification | Reason |
|---|---|---|
| Real crawler IP binding, DNS rebinding, redirects, assets/browser subrequests and sitemap expansion | MERGE BLOCKER | An enforceable transport contract is absent in the actual external runtime; a DNS preflight and mocked responses cannot establish these guarantees |
| Real crawler sealed-job drainage, delayed duplicate starts and cancellation | MERGE BLOCKER | Current status can precede cleanup and submission can reuse job IDs; the runtime cannot provide permanent admission closure/drainage proof |
| Authenticated deployed BFF → backend → Temporal → safe crawler smoke | ACTIVATION PREREQUISITE | Local authorization/dispatch contracts are tested and fail closed; deployed connectivity follows the missing runtime deliverable |
| Live recrawl/delete verification against Qdrant, Neo4j and shared storage | ACTIVATION PREREQUISITE | Frozen target scoping and cleanup ordering are deterministic-tested; physical production sink connectivity/credentials were not exercised |
| Periodic refresh supervisor/cron execution | ACTIVATION PREREQUISITE | Tick claims and races pass real PostgreSQL; the documented every-minute command has not been activated in production |
| Live browser task/event/log inspection and pre-cutover legacy drain | ACTIVATION PREREQUISITE | BFF ownership, authorization, ordering, limits and projections are tested; deployed rollout and drain remain operational steps |
| Production URL traffic migration | ACTIVATION PREREQUISITE | Intentionally unchanged; lack of traffic migration itself is not a merge blocker |

## Diff review

The safe-fetch gate remains closed in both backend and scraper. Sitemap requests
cannot invoke Laravel's fetcher. Retirement gates precede sink cleanup and
artifact unlink. Refreshes contain cadence/due-time state rather than frozen
workflow inputs. Mixed batches never go wholesale to one backend. Unknown and
conflicting owners fail explicitly. Python-owned URL tasks remain readable, including browser-compatible `jobs_*`
counters and signed stage summaries, with no authoritative Laravel task/job/source
rows. Legacy reads are authorized and
merged once with global ordering/limits. Managed compatibility adds only optional
internal compare guards and reuses the existing replacement protocol. Earlier
query, text and workflow-control behavior is covered by regression tests.

Production remains `CRAWLER_INGESTION_BACKEND=laravel` and Python URL ingestion
remains disabled by default. Safe remote crawling is not claimed as complete.
