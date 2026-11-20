# Epoch 15C: staged Python cutover and retained legacy reconciliation

This runbook prepares a later, explicitly approved operation. Shipping code,
passing tests, a healthy process, or elapsed time does not authorize cutover,
reconciliation, retention disposal or Epoch 15B-2. No DROP migration is included.
Keep Laravel auth/BFF/frontend, bridge, legacy history and callback ingress.

**Rollback boundary:** before the first accepted Python ingestion or
dataset/grant mutation, coordinated selector/image rollback remains possible.
After any such mutation, selector-only rollback is insufficient. Keep Python
ownership, workers, artifacts and callbacks available until reconciliation or a
coordinated cross-store restore is approved. Never replay Python-accepted writes
through Laravel. Dataset imports must also remain recorded during rollback.

## Pre-flight

1. Identify the actual deployment and freeze admission/configuration. Record
   branch, reviewed commit, image digests, mounts, effective selectors and
   `php artisan rag:legacy-drain-status --json`. Control automatic legacy
   cleanup/recovery producers through the approved operator process; do not let
   a recurring failed deletion retry race the evidence capture.
2. Record exact source/dataset/document, task/job, workflow/run, writer and
   output ownership. Missing tables, collections, receipts or history are
   unknown evidence. Retain the original failed deletion identity/input.
3. Open the cutover/rollback change record. Name approvers and define the quiet
   window against the actual callback retry/replay and Temporal activity
   policies. The repository specifies no universal window duration.
4. Prepare separate external environment files for staging and the final
   candidate. `docker/env/epoch15c.staging.env.example` and
   `docker/env/epoch15c.cutover.env.example` are overlays, **never automatically
   loaded**. Do not copy either over the live `.env`. Merge with the deployment's
   existing infrastructure settings in the approved secret/configuration system.
5. Validate Compose without printing its expanded secrets:

   ```sh
   docker compose --env-file "$DEPLOY_ENV" config --quiet
   ```

## Backup

With cross-store writers fenced, capture PostgreSQL Laravel and Python schemas,
Temporal `temporal` and `temporal_visibility` persistence, database role/key
references, Qdrant collection snapshots, Neo4j data and shared raw/markdown/
manifest/upload artifacts. Use PostgreSQL `pg_dump -Fc` / `pg_restore`, Qdrant
snapshot/recovery APIs, and ownership/ACL-preserving volume archives. Neo4j
Community `neo4j-admin database dump/load` requires an approved offline context;
do not run it against a mounted running database.

Restore into isolated services at compatible versions. Verify row lifecycles,
exact grants, document/point/contribution identities, receipts and artifact
checksums across stores. Record backup IDs and rehearsal results before traffic
changes. Disabled Temporal archival and expired history cannot be repaired by
pretending an execution terminated. No general managed-history exporter exists.

## Staging deployment — phase A

Build reviewed images only after the backup/rehearsal gate. Deploy Laravel
compatibility code with **all seven selectors explicitly `laravel`**, following
the staging overlay. Keep the current query-all policy until the exact-grant
transition below; do not accidentally inherit HEAD's Python defaults. Admission
remains paused. Keep worker callback URLs on Laravel.

The backend depends on a healthy Laravel container for shared-volume preparation.
Deploying Laravel first under explicit staging selectors satisfies that dependency
without transferring authority. Keep the bridge and compatible legacy workers.
Deploy new workers only after checking history compatibility and drain policy.

## Python schema — phase B

Provision distinct identities through the approved DBA/secret process:

| Role | Intended rights |
| --- | --- |
| Migration | Own/manage only `hawki_backend` objects; temporary migration access |
| Runtime | Non-superuser, no role/database creation; schema USAGE and required DML only |
| Export/audit | SELECT-only Laravel source inventory; no writes or target DDL |

Example privilege *shape*, for later review, with no credentials:

```sql
CREATE ROLE hawki_backend_migrator LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE hawki_backend_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE hawki_cutover_export LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE SCHEMA hawki_backend AUTHORIZATION hawki_backend_migrator;
GRANT USAGE ON SCHEMA hawki_backend TO hawki_backend_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hawki_backend_migrator IN SCHEMA hawki_backend
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO hawki_backend_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hawki_backend_migrator IN SCHEMA hawki_backend
  GRANT USAGE, SELECT ON SEQUENCES TO hawki_backend_app;
GRANT USAGE ON SCHEMA public TO hawki_cutover_export;
GRANT SELECT ON public.datasets, public.dataset_grants,
  public.dataset_grant_tokens, public.users TO hawki_cutover_export;
```

After migration, grant equivalent rights on existing Python tables/sequences.
Do not grant runtime access to Laravel tables, schema ownership, CREATE, or a
superuser role. Audit effective inherited/PUBLIC privileges; a role-specific
REVOKE does not remove rights inherited through PUBLIC. Do not globally revoke
Laravel privileges as an incidental cutover change.

The runtime image deliberately excludes Alembic scripts and operator tools.
Use the reviewed operator checkout and its frozen environment, or a separately
reviewed migration image. Do not run startup/import migrations in the API image.
With credentials supplied silently by the secret system:

```sh
cd python_backend
# MIGRATION_DSN is the dedicated migration credential, not runtime/export access.
HAWKI_BACKEND_DB_DSN="$MIGRATION_DSN" uv run --frozen --no-sync alembic upgrade 0008
HAWKI_BACKEND_DB_DSN="$MIGRATION_DSN" uv run --frozen --no-sync alembic check
```

Verify exactly `0008` in `hawki_backend.alembic_version`, owned tables and runtime
permissions using SELECTs. Keep the runtime DSN separate. Never run an automatic
migration downgrade during rollback.

Canonical pool keys are `HAWKI_BACKEND_DB_POOL_SIZE`,
`HAWKI_BACKEND_DB_POOL_TIMEOUT_SECONDS`, `HAWKI_BACKEND_DB_POOL_RECYCLE_SECONDS`,
`HAWKI_BACKEND_DB_CONNECT_TIMEOUT_SECONDS` and
`HAWKI_BACKEND_DB_STATEMENT_TIMEOUT_MS`. Short names such as `POOL_TIMEOUT`,
`RECYCLE` or `STATEMENT_TIMEOUT` are not aliases and fail strict configuration.

## Keys/JWKS

Provision keys outside checkout, shared artifacts and public storage. Mount the
private key only into Laravel with `docker-compose.bff.yml`; serve public JWKS
through the configured HTTPS origin. Use `docker-compose.bff-ca.yml` only when
an approved CA bundle is needed. Never disable certificate verification.

Set BFF issuer/audience/client/tenant/key ID/private path and matching backend
issuer/audience/HTTPS JWKS/client registry/RS256 allowlist. Mint only existing
server-owned scopes. Validate the actual BFF key fingerprint/JWKS agreement via
`query:cutover-export --check-key` and existing query cutover readiness tooling.
A nonempty path does not prove that the mounted key is valid.

## Dataset/grant import — exact access before query-all is disabled

Historically this deployment has query-all enabled and zero explicit grants.
Simply setting query-all false would remove access. Do this in order:

1. Identify intended active principals and dataset access; approve the exact set.
2. Export current configuration with `query:cutover-export --check-key`. Run
   `query_grants.py --dry-run --expand-default-grants --laravel-report "$REPORT"`
   only as an explicitly reviewed expansion plan. Inspect
   `legacy_grants_required_before_query_all_off`; no automatic grant is implied.
3. While `DATASET_BACKEND=laravel`, materialize approved exact query grants with
   `php artisan dataset:grant-query DATASET_ID USER_ID` and approved ingestion
   grants with `dataset:grant-ingest`. Never grant wildcards or inactive users.
4. Regenerate export/migration plans. Verify the complete exact legacy grant set
   against the approved plan. Source/target drift requires re-review.
5. Only now disable query-all, still under paused admission, and regenerate the
   report with `query_all:false`.
6. Plan/apply/verify Python query grants, ingestion grants and dataset authority
   through the existing tools. The dataset-authority report requires query-all
   false. Example syntax from the operator checkout:

   ```sh
   python scripts/query_grants.py --dry-run --laravel-report "$REPORT"
   python scripts/query_grants.py --apply --laravel-report "$REPORT" --confirm-plan "$REVIEWED_QUERY_HASH"
   python scripts/query_grants.py --verify --laravel-report "$REPORT"
   python scripts/ingest_grants.py --dry-run
   python scripts/ingest_grants.py --apply --confirm-plan "$REVIEWED_INGEST_HASH"
   python scripts/ingest_grants.py --verify
   python scripts/dataset_authority.py --plan --laravel-report "$REPORT"
   python scripts/dataset_authority.py --apply --laravel-report "$REPORT" --confirm-plan "$REVIEWED_DATASET_HASH"
   python scripts/dataset_authority.py --verify --laravel-report "$REPORT"
   ```

Use the documented `HAWKI_QUERY_MIGRATION_SOURCE_DSN`,
`HAWKI_INGEST_MIGRATION_SOURCE_DSN` and `HAWKI_DATASET_MIGRATION_SOURCE_DSN`
SELECT-only credentials and the separately authorized target import credential.
Re-plan between tools and after each successful write; never reuse a stale hash.
Follow the required settled/idempotency apply in the dataset-authority runbook.

Import preserves exact physical scopes, tenants, lifecycle and token hashes;
it does not transfer managed-document/output ownership. Resolve missing active
collections and source IDs absent from the catalog explicitly. Do not invent
placeholder datasets, re-embed content or clear output flags to conceal drift.

## Backend start

Provision the runtime environment with database scope and no
`HAWKI_BACKEND_QUERY_DATASETS` key, even an empty one. Set reviewed model/reranker
configuration, shared roots, stores, Temporal address/namespace/queues and all
backend enable flags from the cutover candidate overlay. The BFF selectors still
remain explicitly Laravel during preparation.

Start the reviewed backend after schema/keys/configuration are ready. An initial
start before imports is permitted for read-only diagnostics on an empty current
schema; do not route traffic or claim data acceptance. Archived imported datasets
are durable Python cleanup work. Both staging/candidate overlays explicitly set
`HAWKI_BACKEND_DATASET_CLEANUP_ENABLED=false`, preventing startup/background
reconciliation from advancing imported archived lifecycles during review. This
does not change the Laravel scheduler fence or weaken direct deletion barriers.
Keep the switch false until legacy archived ownership/disposition is approved;
then enable and observe Python cleanup as a separate operation. Ordinary runtime
default behavior remains enabled.

`/v1/health` remains liveness. Compose backend health now runs bounded read-only
readiness: current schema/runtime role, workflow-control relay, Temporal namespace,
Qdrant, Neo4j, storage permissions, crawler contract, JWKS and model inventory.
A schema guard also refuses startup before the cleanup loop when revision/runtime access is unready. It never executes DDL/imports. Failure leaves the container unhealthy rather than
claiming readiness from Laravel health alone.

```sh
docker compose exec hawki_backend python -m hawki_backend.readiness --live
# From the operator checkout, captures only safe status output:
python scripts/cutover_readiness.py --phase staging --inspect-containers --live
```

The authenticated `/v1/readiness` surface requires `rag:datasets:manage` and returns
503 on failed runtime probes. It does not mutate data. The initial model inventory
probe supports Ollama; other providers deliberately fail until an equivalent
reviewed availability probe exists. Storage permission inspection does not replace
an explicitly approved fixture publication/deletion acceptance test.

## Python read acceptance

Verify valid JWT, wrong signature/expired token rejection, tenant isolation and
exact-grant denial. Query a verified populated authorized dataset, test denied
access, list/read datasets and check effective models/scopes. Run existing deployed
query parity/readiness gates; liveness and an operator assertion do not replace
import verification. Read a proven Python-owned managed document if one exists.
Do not manufacture one as an unapproved smoke fixture.

## Worker/crawler acceptance

The deployed legacy crawler cannot be made safe by dropping required identity.
The external implementation is not in this checkout. Deploy a reviewed crawler
supporting the **permanent-job-v2 capability contract**, structurally checked via
OpenAPI plus health:

- `/crawl` accepts dataset_id, task_id, source_id, source_url, generation,
  url_ingestion_operation_id, permanent patterned job_id and sitemap fields.
- Repeated identical job IDs preserve identity; conflicting input refuses.
- Status returns the same identities in metadata, exact job/external_job IDs,
  known lifecycle state and terminal/sealed/quiescent/admission-closed receipts.
- Status retains those receipts and has patterned job-ID routing and cancellation.
- Protected Chromium namespace/seccomp and connection-bound fetching pass the
  external deployment preflight. OpenAPI is interface evidence, not isolation.

An endpoint reporting HTTP healthy but lacking any required identity remains
unready. Unknown status, missing/conflicting ownership or ambiguous legacy result
never publishes ingestion. Workflow/run identities remain bound by Temporal
activity execution and signed worker events; dataset/task/source/operation/
generation are frozen upstream and passed unchanged through converter/indexer.

Verify worker queue pollers and converter capability/authentication through the
canonical deployment endpoints. Do not run URL jobs until crawler acceptance is
approved. Controlled text or managed-upload fixtures need separate permission.

## Selector cutover — phase C

After imports, read acceptance and all worker/crawler gates pass, keep admission
paused and activate in dependency order across all replicas:

1. Query/text/managed/crawler selectors to Python; query-all already false.
2. Dataset selector Python with database scope in BFF/backend; static registry absent.
3. Pipeline execution Python with authenticated control and URL readiness.
4. Graph operations Python last.
5. Reload Laravel config, PHP-FPM and long-lived scheduler/worker processes with
   the reviewed configuration. Prove all seven effective values before admission.

```sh
python scripts/cutover_readiness.py --phase cutover --inspect-containers --live
```

This report compares actual worker callback secrets/URLs and BFF key readability,
and probes the backend from its container network. It never sends worker events.
It is a readiness report, not import/retention/cutover approval.

## Scheduler fence verification

Under Python dataset authority, `dataset-cleanup` is not registered. Invalid or
incomplete authority also keeps it inert; `resumeArchivedDeletions()` independently
refuses legacy writes. Verify the *reloaded process's* schedule list. Under explicit
Laravel staging/rollback, registration remains intentional; control its retry
activity operationally during evidence gathering. Do not redesign the fence or
assume that changing an environment file reloads an existing scheduler.

## Callback verification

Keep these worker destinations on Laravel, with identical HMAC secret and valid
replay window across replicas:

- `/api/internal/pipeline/worker-events`
- `/api/internal/pipeline/managed-writers`
- `/api/internal/pipeline/managed-deletion-events`

Verify Python ownership/relay availability before activation. During approved
fixtures, prove legacy completion and Python relays. Do not send synthetic live
callbacks during a read-only assessment.

The new `/api/internal/pipeline/managed-deletion-writer-proof` route is an
HMAC-authenticated **read-only proof lookup**, not a callback or release action.
Only typed execution absence triggers it. Its signed response binds the exact
request, original operation/document/dataset, deletion run, writer run, immutable
target digest and current dispatch sequence. It reads positive released registry
proof or an exact durable approved reconciliation, closed gate/protocol/manifest
and recovery/replacement barriers. Missing/stale/unavailable proof refuses cleanup;
no operation input or target is rewritten. Terminal execution inspection retains
all existing Laravel dispatch, immutable receipt and sink-scope barriers.

## Drain re-measurement and reconciliation dry run

Capture fresh counts after acceptance. Default writer reconciliation is read-only:

```sh
php artisan rag:legacy-drain-status --json
php artisan documents:reconcile-writer SOURCE_ID /shared/approved-manifest.json --json
php artisan documents:reconcile-writer SOURCE_ID /shared/approved-manifest.json --confirm-quiescence --evidence-ref=EXISTING_APPROVED_RECORD --json
php artisan pipeline:reconcile-terminal-projections --json
```

`--confirm-quiescence` is an operator assertion of externally established writer,
schedule and outstanding sink drainage. It is not permission to skip evidence.
A reference identifies an actual approved record covering exact executions,
retention and quiescence; no command fetches or authenticates that record.

Assessment validates source/document/dataset ownership, exact execution history,
released registrations, manifest identity/scope and required artifact/content-hash evidence and
recovery/replacement. Active/uncertain registrations always refuse. In particular,
the audited active-attempt cases cannot be solved with an evidence reference.
Missing artifact roots, graph evidence or identities also refuse. Namespace errors,
generic 404s, timeout and malformed responses are unavailable, never retention proof.

## Approved reconciliation

Only after reviewing the concrete dry-run and obtaining independent approval:

```sh
php artisan documents:reconcile-writer SOURCE_ID /shared/approved-manifest.json --apply --confirm-quiescence --evidence-ref=EXISTING_APPROVED_RECORD --json
php artisan pipeline:reconcile-terminal-projections --job-id=EXACT_JOB --apply --evidence-ref=EXISTING_APPROVED_RECORD --json
```

Omit a retention reference only when all required executions are positively
terminal. Writer apply locks/revalidates source, document, jobs, outputs,
operations, registry and manifest before atomically recording evidence and
closing the gate. It never releases active writers. Identical reconciliation
returns `already_reconciled` without rewriting rows/audit. Legacy operation
retirement is a separate explicitly approved action, requiring every source's
prior reconciliation and terminal deletion proof; it is not a retention override.

Resolve the original failed deletion through its retained compatibility protocol,
positive writer proof and signed immutable receipt. Never retry it automatically
from this runbook, change its key/identity, or equate missing stores with completion.
Classify each retained output for approved retention/ownership transfer/deletion;
there is no general ownership-transfer importer. Re-measure all drain counters.

## Quiet window

At T0 capture drain JSON, row counts/status distributions/content digests and
available write timestamps, callback HTTP streams including rejected/duplicate
attempts, scheduler state, Temporal executions/schedules/pollers and pending sink
activity. Include legacy datasets/grants/tokens, documents, tasks/jobs/stages,
sources, outputs/deletions, events and telemetry. Some tables lack update times;
counts or MAX(updated_at) alone are insufficient.

Control recovery and historical synchronization writers during the approved
window, repeat captures and investigate every difference. Zero current workflows,
an old callback timestamp, a replay-window interval or expired retention is not
quiescence proof. Operators must approve the duration and evidence disposition.

## Rollback conditions and exit

Pause admission on any failed grant/scope/model/readiness/callback acceptance,
unknown owner or new legacy write. Before Python mutations, reverse graph →
pipeline → dataset → query/ingestion selectors, restore reviewed image/configuration
pairs and verify ownership before reopening. Preserve additive schema/imports,
callback ingress and the bridge. Scheduler restoration needs explicit owner review.

After Python mutations, preserve Python routing for accepted work and use an
approved reconciliation/restore plan. Do not downgrade/drop Python schema, revive
tombstones, re-enable query-all to hide missing grants or replay accepted writes.

Epoch 15C exits only with healthy reviewed runtime/schema, verified imports and
scopes, seven Python selectors, query-all false, fenced legacy admission,
functional callbacks, reconciled stages/writers/deletion/output ownership, zero
pending recovery/replacement, resolved Temporal activity, production acceptance
and an approved quiet window. Retention approval, restore-tested backups and
formal rollback closure remain additional mandatory gates before 15B-2.

## 15C-1 review fixes: evidence and readiness contracts

Canonical index manifests are the unversioned `IngestManifestRecord` list emitted
by `indexing/artifact_documents.py`: every record requires `document_id`,
`relative_path`, `markdown_path`, and `content_hash`. Reconciliation requires the
physical artifact inside its source root. There is no approved checksum-free
historical format; omitted evidence or unknown version markers refuse even with
an evidence reference. `content_hash` covers the producer's effective UTF-8 text:
explicit original-content hashes are preserved; converter metadata normalization
uses `strip_leading_converter_markdown_noise`. Optional `sha256` is a separate
raw-byte checksum and must match independently. Changing/removing evidence cannot
make an otherwise invalid artifact eligible.

Crawler preflight now requires the **external** OpenAPI extension
`x-hawki-crawler-contract: permanent-job-v2`, typed required admission and status
schemas, ownership metadata and the supported lifecycle enum. An empty response
schema, an absent capability or a future contract refuses readiness. The external
crawler must implement and advertise this contract; RAWKI does not add it locally.

Runtime probes resolve Ollama through `OLLAMA_API_URL`, Qdrant through its runtime
scheme/host/port settings, and Neo4j through the graph adapter settings. Conflicting
Qdrant read/write destinations refuse. `OLLAMA_BASE_URL` is unsupported.
The intended `external` reranker uses the local HAWKI `hawki-cohere-v1` contract
and the bounded inference probe documented below. `none`/`cosine` retain local
semantics; Jina and unknown dialects fail closed. Never disable ranking to pass.

Ordinary runtime readiness omits disabled ingestion/crawler/workflow dependencies.
Staging/cutover validation still requires the full intended feature set. Database
mode always exposes dataset management, so dataset/grant/token privileges remain
required even with ingestion disabled. Dataset deletion retains source-count reads and admission retains the graph-clear
fence even with ingestion disabled. Database mode also exposes OpsService: graph
clear retains ledger writes and SELECT/UPDATE on the source tables it locks.
Unique ingestion event/request/mutation writes are added only for enabled features.
Catalog checks verify schema USAGE, forbid schema CREATE, and include UPDATE for
SELECT FOR UPDATE (including URL grant rows and managed/URL events). Text events
use plain reads and require only SELECT/INSERT. Advisory function EXECUTE is checked. Current lifecycle deletion is soft; runtime requires no
physical DELETE. Serial/identity sequences, if present, require USAGE/SELECT.
No catalog probe attempts a write or executes a migration.

The deployment report retains `laravel.*`, `backend.*`, and
`laravel.network.*` results independently. Any failure/unknown or nonzero component
exit status refuses overall readiness. It explicitly runs
`php artisan rag:cutover-relay-readiness` as www-data inside Laravel. That read-only
command uses effective Laravel configuration, checks all three signed callback
ingress routes, probes each owner's signed readiness at its actual relay origin,
and sends a real BFF-signed dataset-management credential to the non-mutating
`/v1/readiness/identity` verifier. This verifies the deployed key/JWKS, issuer,
audience, client, tenant, algorithm and required scope without sending a callback
or manufacturing a dataset/user. Output contains bounded status only.


## Final R1–R5 readiness contracts

Qdrant consumers retain separate readiness results and credentials:

| Consumer | Runtime resolver / URL | Credential | Runtime transport / timeout |
| --- | --- | --- | --- |
| Query | `qdrant_settings_from_env`: QDRANT_SCHEME/HOST/PORT | QDRANT_API_KEY | requests; QDRANT_TIMEOUT, default 30s |
| Indexer publication | Same resolver in indexer container | Its own QDRANT_API_KEY | Same requests transport |
| Text deletion | `load_text_ingestion_settings`: HAWKI_BACKEND_TEXT_INGESTION_QDRANT_URL | HAWKI_BACKEND_TEXT_INGESTION_QDRANT_API_KEY | httpx; 15s |
| Managed/URL deletion | `load_sink_settings`: QDRANT_HTTP_URL | QDRANT_API_KEY | Same deletion transport; 15s |
| Dataset/ops catalog | `QdrantCatalog.from_env`: QDRANT_HTTP_URL | QDRANT_API_KEY | httpx; 3s |

Readiness inventories collections with each required consumer's actual transport.
It requires every active canonical collection to exist. Catalog dependencies remain
when ingestion is disabled. All required destinations must agree; differing
consumer credentials are supported and independently probed. Credentials are never
printed. Requests retains its runtime CA/proxy environment semantics, including
REQUESTS_CA_BUNDLE; httpx retains its runtime SSL_CERT_FILE/SSL_CERT_DIR/proxy
semantics. The bounded readiness request budget is 3s rather than the longer
publication/deletion budget. The deployment report separately checks the indexer's
destination and probes its own credential inside that container, without writes.

Crawler compatibility uses a conservative reviewed constraint profile rather than
sample strings or arbitrary regex inclusion. Actual URL IDs are `ingest_` plus 24
hex characters, `source_` plus 32 hex characters, and 64-hex operation digests.
Caller task IDs may contain arbitrary text up to 191 characters. Generations
support 1 through PostgreSQL INTEGER's maximum (2147483647). Unknown narrowing
predicates, incompatible length/enum/format bounds and unreviewed patterns refuse.
Sitemap admission fields are optional boolean `sitemap`/`sitemap_only` and string
`sitemap_base`; URL lists are expanded upstream, not sent as nested crawler arrays.
URL strings may contain Unicode, so restrictive ASCII-only URI formats refuse;
reviewed IRI formats or unconstrained strings are supported. Status has a required
path job parameter and the existing typed, identity-bound
receipt. No additional pagination contract is invented.

The intended external reranker is the repository's local CrossEncoder service,
not a generic LiteLLM/Jina endpoint. Runtime and readiness share endpoint, Bearer
key, dialect and optional model resolution. Cutover requires an explicit
RERANKER_MODEL matching HAWKI_RERANKER_MODEL. The server rejects a mismatched model
and an invalid configured key before prediction, and returns its actual model.
The probe sends one fixed, non-sensitive document to the actual runtime rerank
endpoint, with a 3s connect/5s read budget, no redirects, and validates model,
index, document and finite score. It creates no work, callbacks or application
records. Normal first-use model loading may populate the service's model cache;
provision/warm that model before claiming readiness. The server key is
HAWKI_RERANKER_API_KEY, defaulting to RERANKER_API_KEY in the shared environment.
Unknown dialects and Jina remain unsupported by this probe. Authentication may be
omitted only when the actual local service is deliberately configured without it.

Ordinary readiness reads graph flags and namespaces from the canonical database.
With graph/control/managed/URL capabilities unused, all active vector-only scopes
omit Neo4j. Unknown or incomplete graph scope refuses. Graph ops selected for
Python, graph-capable workflow dispatch, managed/URL historical cleanup, or any
active graph-enabled scope requires Neo4j. Complete staging/cutover readiness
continues to require graph availability explicitly; the deployed backend probe
receives the selected phase. This does not change routing or artifact semantics.


## Final V1–V3 contract corrections

Every required crawler scalar is checked, including admission event/sealed, both
lifecycle fields, all receipt booleans, and the page counter. Finite lifecycle
domains and admission sealed=false support exact enum/const, allOf, anyOf, oneOf
and not evaluation. Every required value must satisfy the schema, oneOf must
match exactly one branch, and unknown predicates in any branch refuse. Infinite
identity/counter domains remain conservative: unsupported combinators or bounds
refuse. The seal timestamp retains its exact reviewed nullable date-time shape.
Nested required objects are recursively checked through the same field validators.

Managed and URL cleanup independently require EXECUTE on the bigint overloads of
pg_advisory_xact_lock, pg_try_advisory_lock and pg_advisory_unlock. Text-only
ingestion requires only pg_advisory_xact_lock(bigint). Disabling URL never removes
managed requirements. Readiness checks catalogs without acquiring any locks.

The hawki-cohere-v1 external reranker endpoint must be canonical: redirects are
unsupported. Runtime and readiness both send allow_redirects=false and accept
only direct 2xx responses before reading the response body. A valid-looking body
on 3xx/4xx/5xx is never success evidence, and Bearer credentials are never forwarded
to a redirect destination. Runtime retains its existing failure fallback to the
original retrieval order; readiness reports failure.
