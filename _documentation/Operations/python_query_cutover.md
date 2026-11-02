# Query cutover: Epoch 7C operator runbook

Implementation does not activate traffic. The checked-in selector remains
`HAWKI_QUERY_BACKEND=laravel`. Only `POST /api/query` changes execution backend;
dataset discovery, MCP, graph APIs, ingestion, Temporal and admin APIs retain
their current owners. There is no automatic fallback or dual execution.

## Safety and identity

`tenant_id="default"` is a migration convention for the current tenant-less
Laravel database, not a permanent multi-tenant design. JWT `sub` is the exact
decimal string of the active Laravel user's ID; grants use type `user` and
permission `query`. No wildcard, browser-selected tenant or identity fallback
is introduced. Another tenant in source/target stops migration. Removed users
are excluded and unexpected existing target query grants block apply.

Use a maintenance/change freeze for dataset metadata, user activation, grants,
persisted model settings and graph indexing throughout export, review, backfill
and activation. The tool holds a read-only repeatable-read source snapshot and
a separate atomic target transaction; it cannot provide a distributed snapshot
across Laravel's settings file, graph state and two database connections.
Refresh the export after any change. Reports expire after **300 seconds**;
review longer plans, then regenerate and compare their stable `plan_sha256`.
An unchanged plan must have the same hash. Concurrent target writes are locked
during apply. Never run backfill concurrently with independent grant management.

The operator Python tool is the only transition code permitted to read
`public.datasets`, `public.dataset_grants`, `public.users`. Runtime `src/**`
remains independent. Source credentials MUST have SELECT privileges only.
Target credentials may SELECT/INSERT/UPDATE only the Python-owned tables; schema
provisioning uses a separately authorized migration account. No tool runs at
startup, creates schemas, deletes rows, writes Laravel tables, or writes env files.
Use host-reachable `postgresql+psycopg://` DSNs from a secret manager; no DSNs in
command arguments, shell history, tickets or reports. Never enable SQL echo.

This remains an operator synchronization process after activation. Laravel dataset,
grant, model and graph-readiness changes are not automatically mirrored into
Python. Keep provisioning changes frozen until a reviewed re-export/backfill/
verify/registry deployment can preserve both paths; do not reopen grant/dataset
creation merely because the selector smoke test passed. No dual writes or new
runtime dependency on Laravel tables were added.

All source datasets migrate with identity-preserving active/archived/deleted
status mapping. Query grants for active users migrate, including grants on
inactive datasets; inactive status continues to deny queries. Ingest grants are
ignored. Unknown permissions/types, malformed IDs, duplicate rows and source
drift fail closed. Extra target datasets/query grants require manual investigation;
apply refuses them instead of deleting or adopting them.

**Query-all requires two explicit decisions.** `--expand-default-grants` plans
only active users × active datasets and only while Laravel query-all is true.
The exact identities and counts are in the dry run. Python grants alone do not
make Laravel rollback safe: materialize the reviewed missing Laravel grants with
the existing `dataset:grant-query` command while query-all is still true. This
does not expand effective access. The readiness gate requires that none of these
rollback grants remain missing. Only then disable query-all, test legacy access,
and later activate Python. A Python grant does not confer login/token ability.

## Commands and report contract

Run from repository root on an operator machine with Docker access, uv, PHP and
Composer dependencies. The deployment container names below are this Compose
stack's names. Deploy the updated app image/commands first, keeping the selector
`laravel`; these commands must execute against the intended staging/deployment,
not a different host's checkout or database. Never rebuild/recreate production
services merely to collect validation evidence without the deployment procedure.

```zsh
umask 077
CUTOVER_DIR=$(mktemp -d /private/tmp/rawki-cutover.XXXXXX)
REPORT="$CUTOVER_DIR/laravel.json"
# Obtain both DSNs from the secret manager; this zsh prompt avoids history/echo.
read -rs 'HAWKI_QUERY_MIGRATION_SOURCE_DSN?SELECT-only source DSN: '
export HAWKI_QUERY_MIGRATION_SOURCE_DSN
read -rs 'HAWKI_BACKEND_DB_DSN?Host-reachable target DSN: '
export HAWKI_BACKEND_DB_DSN
MIGRATE=(uv run --project python_backend --group test python python_backend/scripts/query_grants.py --laravel-report "$REPORT")
docker exec hawki_rag_app php artisan query:cutover-export --check-key > "$REPORT"
# For metadata preparation before keys exist, omit --check-key. Key gate will NOT PASS.
"${MIGRATE[@]}" --export-scope > "$CUTOVER_DIR/datasets.json"
jq -cS '.models' "$REPORT" > "$CUTOVER_DIR/models.json"
```

Export contains only effective metadata, guard drivers and optional public key
fingerprint/size/path; no JWT, private key, provider credential or DSN. Protect it
because dataset names/physical identifiers can still be sensitive. It calls the
existing `DatasetGraphAvailabilityRepository::hasReadyGraph`, including current
upload/output state and direct-text exclusions; a namespace alone does not imply
graph readiness. Provider chat/vision models come from the existing effective
`SettingsService::modelRuntimeForProvider`, including persisted settings. Embedding
models remain each dataset's pinned model, never the provider's current default.
Every active dataset must have valid physical metadata for readiness. This is
deliberately more conservative than discovery's Qdrant-collection existence filter.

Publish the exact contents of datasets.json/models.json as the server-side
`HAWKI_BACKEND_QUERY_DATASETS` / `HAWKI_BACKEND_QUERY_MODELS` values through the
deployment system; this tool never edits those values. A successful export alone
does not validate target state. `--verify-scope --inspect-containers` checks the
actual backend environment. Registry JSON must match exactly: no stale/extra
datasets, physical target, provider/model, namespace or graph flag drift.

```zsh
# Add --expand-default-grants only after explicitly approving the existing all-access policy.
EXPANSION=(--expand-default-grants)  # use EXPANSION=() when query-all is already false
"${MIGRATE[@]}" --dry-run "${EXPANSION[@]}" > "$CUTOVER_DIR/plan.json"
jq '{counts,changes,target_check,plan_sha256,grants,legacy_grants_required_before_query_all_off}' "$CUTOVER_DIR/plan.json"
# Source-only planning is possible with target DSN unset, but target_check then
# says NOT EXECUTED and changes=null. Provision target and rerun before approval.
PLAN_SHA=$(jq -r '.plan_sha256' "$CUTOVER_DIR/plan.json")
"${MIGRATE[@]}" --apply "${EXPANSION[@]}" --confirm-plan "$PLAN_SHA"
"${MIGRATE[@]}" --verify "${EXPANSION[@]}"
"${MIGRATE[@]}" --apply "${EXPANSION[@]}" --confirm-plan "$PLAN_SHA"
# Required second result: datasets_inserted=0, datasets_updated=0, grants_inserted=0.
"${MIGRATE[@]}" --verify-scope --inspect-containers
```

`--dry-run` prints the full desired grant set, exact missing inserts/status updates
when a target is configured, and missing legacy rollback grants. It writes nothing.
`--apply` requires a reviewed deterministic plan hash, inserts datasets before
grants and verifies within one target transaction; any failure rolls it back.
`--verify` reports counts/missing/extra/mismatched; all discrepancies exit 1.
Successful checks exit 0; argument errors exit 2. Operational errors are sanitized.
The plan hash confirms desired data/config, not approval by itself: the operator
must compare/review the report, changes and effective access before invoking apply.

To materialize reviewed rollback grants, invoke **one command per exact pair** in
`legacy_grants_required_before_query_all_off`, for example using placeholders:

```zsh
docker exec hawki_rag_app php artisan dataset:grant-query DATASET_ID USER_ID
```

Do not paste unreviewed JSON into `eval` or silently run a generated mass grant.
Refresh the Laravel report and dry run afterward; required legacy grants must be
empty and the desired expanded plan must remain unchanged. Once query-all=false,
remove `--expand-default-grants` from all subsequent checks and plans.

## Readiness, runtime settings and live evidence

```zsh
docker exec hawki_rag_app php artisan query:cutover-export --check-key > "$REPORT"
"${MIGRATE[@]}" --cutover-check --inspect-containers --phase prepare
# Safe structural audit; external checks show NOT EXECUTED and exit is nonzero.
# Explicit staging execution, only after all structural gates pass:
"${MIGRATE[@]}" --cutover-check --inspect-containers --phase prepare --live
# After separate query-all=false transition, refresh report and run:
"${MIGRATE[@]}" --cutover-check --inspect-containers --phase activate --live
```

PASS requires **every check executed and green**. FAIL and NOT EXECUTED both
prevent activation. `prepare` allows query-all=true but requires all intended
access in both Python and explicit Laravel grants; `activate` additionally
requires query-all=false. No command changes the selector or authorization policy.
Readiness also reads the actual backend database through its deployed connection
and compares an exact status/grant digest in memory. A correct operator-host DSN
alone cannot accidentally validate a different runtime database.
Missing schemas/credentials fail safely; provision using the existing
`uv run --project python_backend alembic -c python_backend/alembic.ini upgrade head`
only under the separate database migration approval, then restore least privilege.

`--inspect-containers` reads Docker environments into memory and compares all names
with RAG_, QDRANT_, NEO4J_, RERANKER_, OLLAMA_, LITELLM_, OPENAI_, ANTHROPIC_,
JINA_, GRAPH_, EMBEDDING_, GEMINI_, GOOGLE_, AZURE_, COHERE_, VOYAGE_ prefixes.
Only variable names and PASS/FAIL/NOT EXECUTED reach the report. Required RAG names
are listed in `scripts/query_readiness.py`; explicitly supply their effective
bridge values to both services. A missing name is NOT EXECUTED rather than an
assumed default. Credentials are compared in memory without values/fingerprints.
Audit any additional selected provider variables outside those prefixes manually.
Live queries must also prove the selected embedding provider is usable; generated
answer/vision-provider availability requires the later generation smoke check.

Reranker comparison uses effective bridge defaults: RERANKER_MODE (default none),
RERANKER_MIX_MODE (default true), RERANKER_MIX_WEIGHT (default .5) and request
rerank_top_n=20. For this deployment's external reranker, configure Python
RERANKER=external, TOP_N=20, MIX_MODE=true, MIX_WEIGHT=.5 under the
HAWKI_BACKEND_QUERY_ prefix; verify rather than assume the bridge still has these
values. External mode requires a configured URL; API key and other reranker
settings must agree through the shared environment gate. Do not change algorithms.

Auth requires exact issuer/audience agreement, hawki-bff→user, tenant_id claim,
RS256 only, nonempty kid and BFF TTL 1..60 seconds within the verifier maximum.
Use a dedicated verifier profile with max TTL 60 where appropriate; do not break
other registered clients by silently narrowing their deployed configuration.

Real keys, public JWKS and HTTPS provisioning follow
[the BFF key instructions](python_query_bff.md#opt-in-key-and-jwks-mounts).
The `--live` gate inspects **all** containers for private-mount sharing, rejects
writable/checkout-backed keys, checks RSA≥2048 using Laravel's mounted key report,
then runs HTTPS fetching and a fresh production JWKS provider inside Python.
TLS must validate without redirects. Public-only JWKS and the selected public key
fingerprint must match the private key. No production keys are generated here.
The real BFF smoke probes prove signed-token admission without exposing the JWT.

Live parity has **no golden/toy inventory fallback** and no in-process mocked auth.
It reads the fresh actual Laravel export, compares it with the deployed registry,
checks Qdrant dataset payloads and Neo4j dataset **and namespace**, and invokes
`query:cutover-probe` for a fresh BFF token for each real Python query. Bridge
requests use identical models, scope and configured reranker settings, generate=false.
Select a query that actually returns graph evidence for graph-on; empty graphs
are not a successful graph gate. Only read/query operations run, no ingestion.

Before `--live`, provide actual staging values (not example dataset IDs):

```zsh
export HAWKI_PARITY_INVENTORY_FILE="$REPORT"
export HAWKI_PARITY_USER_ID='ACTUAL_ACTIVE_STAGING_USER_ID'
export HAWKI_PARITY_GRAPH_OFF_DATASET='ACTUAL_AUTHORIZED_DATASET_ID'
export HAWKI_PARITY_GRAPH_ON_DATASET='ACTUAL_AUTHORIZED_GRAPH_DATASET_ID'
export HAWKI_PARITY_QUERY='ACTUAL_QUERY_WITH_MATCHING_VECTOR_AND_GRAPH_DATA'
export HAWKI_PARITY_BRIDGE_URL='http://STAGING_BRIDGE_HOST:PORT'
export HAWKI_PARITY_PYTHON_URL='https://STAGING_PYTHON_HOST'
export HAWKI_PARITY_QDRANT_URL='http://STAGING_QDRANT_HOST:PORT'
export HAWKI_PARITY_NEO4J_URL='http://STAGING_NEO4J_HOST:PORT'
# Set HAWKI_PARITY_QDRANT_API_KEY if required and HAWKI_PARITY_NEO4J_AUTH via secret manager.
# Supply a freshly signed, short-lived staging-only wrong-tenant credential,
# using the same legitimate issuer/audience/client/user and a tenant without grants.
# Keep it in a mode-0600 file outside the checkout. Never paste tokens into commands.
export HAWKI_PARITY_WRONG_TENANT_TOKEN_FILE='/secure/STAGING_ONLY_TOKEN_FILE'
HAWKI_PARITY_INTEGRATION=1 uv run --project python_backend --group test pytest python_backend/tests/parity/test_live_scope_integration.py -q
```

The negative test runs first to avoid expiry during slow retrieval. It requires
401 for no credential and 404 for an otherwise valid wrong-tenant token; an
invalid/expired token producing 401 cannot masquerade as tenant-isolation proof.
Provision that credential independently in staging; do not change the live
Laravel tenant, expose signer endpoints, or create persistent test principals.
Standalone pytest may contain sensitive assertion data on failures: run in a
protected operator console. Readiness captures that output and reports only gate
status. Missing live inputs fail, never skip. All four tests and zero skips are
required. Run with safe staging inventory only. No staging inventory means
**NOT EXECUTED — ACTIVATION PREREQUISITE**, never PASS.

## Reversible 44-step procedure

These are operator actions, not actions executed by the implementation pass.
Keep an evidence/change record without credentials. Follow the deployment's
existing Compose layers and env-file path; examples below assume the default
`docker compose` already resolves the correct layers, including opt-in BFF mounts.
Use deployment/secret-manager editing for settings rather than modifying code.

1. Provision the signing key outside repository/build contexts; check ownership.
2. Provision its public-only JWKS, with unique kid and RSA/RS256/sign/verify metadata.
3. Publish the final certificate-valid HTTPS JWKS URL, reachable from Python.
4. Configure Python auth issuer/audience/clients/RS256/tenant claim/TTL and trust.
5. Confirm canonical default tenant with operators; stop if real tenants differ.
6. Freeze inventory/settings changes and export fresh Laravel metadata/scope above.
7. Publish exact dataset registry; provision Python schema separately if absent.
8. Publish exported effective chat/vision registry; retain pinned dataset embeddings.
9. Match effective reranker and its endpoint/credential configuration.
10. Match all shared retrieval settings; recreate only required services for env updates,
    retaining HAWKI_QUERY_BACKEND=laravel and the existing query-all policy.
11. Run dry-run, explicitly adding expansion only if approved and query-all is true.
12. Review exact access pairs/counts; materialize the missing explicit Laravel rollback
    grants using the reviewed per-pair commands; refresh and re-review the plan.
13. Apply with reviewed plan_sha256. No unexpected extra rows may remain.
14. Run verify; require no missing/extra/mismatched rows and exact scope equality.
15. Re-run apply; require all three change counts zero.
16. Run `docker exec hawki_rag_app php artisan query:cutover-probe USER_ID DATASET_ID`;
    generate=false, fresh real signed admission, selector still laravel.
17. Run inventory→storage live test with actual inventory and no skipped tests.
18. Run graph-off parity (IDs/order/scores/count/KG) against real bridge/Python.
19. Run graph-on parity, scoped namespace and physical-identifier stripping checks.
20. Run the no-auth/wrong-tenant test with a fresh protected staging credential.
21. Refresh export and run readiness `--phase prepare --live`; require PASS.
22. Separately set HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT=false through deployment.
23. Recreate app with its updated environment, then
    `docker exec hawki_rag_app php artisan config:cache`; retain selector laravel.
24. Verify intended users' Laravel list/query access using their normal browser/API
    credentials and `query:cutover-probe USER_ID DATASET_ID --legacy`; compare approved
    access matrix. Refresh export, remove expansion flag, verify data and run
    readiness `--phase activate --live`. Stop on access loss or any non-PASS gate.
25. Record current selector, query-all value, image IDs, deployment revision and
    safe readiness results; obtain the environment's activation authorization.
26. Only now set HAWKI_QUERY_BACKEND=python in deployment settings.
27. `docker compose up -d --no-deps --force-recreate hawki_rag_app` using the
    unchanged approved image and correct layers, then `php artisan config:cache`
    inside that app; do not combine this with a query-all change or rebuild.
28. Through normal authenticated POST /api/query, smoke a vector query and check UI.
29. Through the same route smoke graph retrieval and correct dataset isolation.
30. Smoke generation if enabled using the actual configured model/provider.
31. With a real ungranted staging user/dataset, require the expected denied query.
32. Re-run the valid wrong-tenant direct-Python negative check (never alter BFF tenant).
33. Monitor POST /api/query 2xx rate against the baseline.
34. Monitor 401/403/404 changes with resolved identities/access policy in mind.
35. Monitor authorization_unavailable responses without logging credentials.
36. Monitor dataset_not_ready responses and registry/data drift.
37. Monitor 5xx/502/504 transport/provider errors; no automatic legacy retry.
38. Compare latency/timeouts to the prior bridge baseline.
39. Monitor Qdrant/Neo4j/provider failures and reranker availability.
40. Check bridge /query traffic attributable to POST /api/query stops; MCP and
    other independent surfaces legitimately continue bridge traffic.
41. If rollback is required, explicitly restore HAWKI_QUERY_BACKEND=laravel only.
42. Recreate app from the same image and reload config as in step 27; no rebuild.
43. Verify normal list/query availability and grant-scoped legacy queries; maintain
    query-all=false because explicit Laravel grants were verified before activation.
44. Keep Python grants, registry, auth and key configuration. Do not delete them as
    rollback cleanup. Unfreeze changes only after stabilization and record drift.

## Advisory assessment (2026-11-02)

Installed `laravel/framework` is **12.64.0**, affected by
[CVE-2026-114509 / GHSA-f9x3-7vq9-cph6](https://github.com/advisories/GHSA-f9x3-7vq9-cph6).
The upstream advisory describes non-string API credentials reaching Laravel's
TokenGuard, especially with MySQL/MariaDB. Patched 12.x is 12.68.0. The installed
`vendor/laravel/framework/src/Illuminate/Auth/TokenGuard.php` contains that lookup.

Repository reachability evidence: `config/auth.php` defines web/session only;
`BrowserQueryPrincipalService` resolves that user or Sanctum. Installed Sanctum
4.3.3 builds `Illuminate\Auth\RequestGuard` in its ServiceProvider, not TokenGuard.
`QueryTokenSigner` uses firebase/php-jwt 7.2.1 RS256 and Python validates with its
independent JWT verifier; neither calls TokenGuard. No token-driver registration
or auth:api route was found in app/config/routes. Thus the identified critical
surface is **not directly reachable through the checked-in query/BFF path**.
This is a reachability assessment, not a claim the installed framework is patched.
Readiness rejects any exported runtime guard with driver token, or an unknown
default guard. Custom deployment auth extensions must be reviewed separately;
if they introduce TokenGuard on this path, activation is blocked pending remediation.

Current Composer audit remains non-green: **21 advisories across seven packages**.
Besides Laravel: Guzzle (host normalization/cookie scope), CommonMark (XSS/DoS),
Flysystem (path normalization), Symfony HttpFoundation (header parsing DoS), Mime
(mail headers), YAML (lexer recursion). BFF uses a configured fixed URL, disables
redirects/cookies and never accepts browser upstream URLs; the Guzzle issues do
not create a new host-filter/cookie boundary here. CommonMark/Mime/YAML/Flysystem
are not used to authenticate or mint BFF tokens. HttpFoundation request-header
DoS is a pre-existing shared HTTP exposure, deserving separate prompt remediation
and ingress limits, not dismissal as harmless. The framework relationship/debug
advisories likewise need independent remediation review. No dependencies were
upgraded or advisories suppressed to make validation green. Obtain the deployment
owner's risk decision on inherited exposures before activation and schedule a
focused patched-dependency change with regression tests.

## Implementation validation (2026-11-02)

Laravel: 197 unit tests (699 assertions), 108 query/auth/authorization feature
tests (477 assertions), Pint and strict Composer validation passed. Composer
audit reports the 21 inherited advisories above and remains non-green.

Python: 827 default non-integration tests passed, 28 opt-in tests skipped and
four live tests deselected. Included subsets: 46 migration/readiness tests,
112 deterministic parity/helper tests, eight real PHP→Python JWT interop tests
using ephemeral test keys. Ruff lint/format and frozen lock verification passed.
SQLite tests cover full CLI dry-run/apply/verify/idempotency and rollback; actual
PostgreSQL backfill integration and real signed deployment admission were not run.

Both service images built. The built Laravel image exposes both operator commands
and its JWKS Nginx fragment passes syntax validation. The isolated built backend
returns health 200 and unconfigured query 503. Running services were not recreated;
the running local app selector was confirmed laravel and backend health 200.

The local running backend has no auth, DB or query registry configured; the
application database had no hawki_backend schema. Shared environment comparison:
82 names match, zero mismatches, 15 required names not explicitly configured
(all REQUIRED_RAG names in query_readiness.py except RAG_GENERATE_ANSWER).
The running bridge uses external reranking, mix=true, weight=.5 and a configured
URL; its API key is unset. Python query reranker is unset. Real scope/model parity,
key mounts, public JWKS/TLS, backend database contents and live staging parity
remain **NOT EXECUTED — ACTIVATION PREREQUISITE**. No production activation or
database/grant/policy operation was performed. Implementation completion does
not represent deployment readiness or production cutover completion.
