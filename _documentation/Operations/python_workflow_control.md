# Python workflow control — Epoch 8

Before: Laravel application services → bridge Temporal endpoints → Temporal → Python workers.

After: Laravel compatibility/application boundary → signed Python backend operations
→ Temporal → separate Python workers. Public text, document and URL ingestion
ownership remains in Laravel until Epochs 9–11. Query selection stays `laravel`.

## Ownership and trust

Laravel still authenticates public ingestion requests, validates datasets and
documents, prepares immutable artifact/sink scope, persists its application rows,
fences managed writers and verifies worker callbacks. Those callbacks, writer
release requirements, historical cleanup scope and generation publication are
unchanged. Its stable ID projections remain necessary for preparation inside SQL;
Python validates them against the authoritative identity rules before dispatch.
Retry identities are requested from Python, including predecessor safety checks.

The backend owns workflow selection, IDs, reuse policies, task-queue validation,
Temporal timeouts, scheduling, status, cancellation, dispatch deduplication and
uncertain-start reconciliation. `hawki-workflow-control` contains only the SDK
adapter; backend images do not contain workflow or activity workers.

All `/internal/workflow-control/*` calls require `X-Hawki-Timestamp` /
`X-Hawki-Signature` HMAC-SHA256 with `HAWKI_RAG_WORKER_CALLBACK_SECRET`, a
300-second window and constant-time comparison. The signature is `v1=<hex>` over
these exact UTF-8 bytes (LF separators; no final newline is added to the body):

```text
hawki-workflow-control-v1
POST
/internal/workflow-control/<operation>
<timestamp>
<exact request body bytes>
```

The audience is a fixed protocol identifier. The method and canonical routed
operation are authenticated; Host, URL origin and audience headers are ignored.
The verifier does not accept the former timestamp/body-only control signature.
Deploy matching Laravel signer and backend verifier releases while dispatch is
quiesced. Worker callback signatures retain their existing contract.
JWT/browser credentials do not authorize this surface. It is excluded from public
OpenAPI. Keep it on internal networks. Empty secret, disabled control or missing
database configuration fails closed. Authenticated application preparation is the
source of physical artifact/sink scope until the later ingestion migrations;
browser payloads must never be forwarded to it directly.

Explicit operations are `source/start`, `text/start`, `deletion/start`,
`source/retry-identity`, `schedule/upsert`, `schedule/delete`, `execution/status`,
`execution/cancel`, and `execution/stop-for-deletion`. There is no arbitrary
workflow-name/namespace/address/queue parameter. Supplied historical queue maps
must agree with the deployed server configuration; freeze queue settings during
the handoff. `managed_replacement` is application intent, not a reuse-policy option.
Both recovery entry points lock the application job to reserve one
`metadata.pending_recovery` attempt before requesting an identity. That record
retains the predecessor, attempt number and durable workflow type. The selected
workflow ID, frozen input and input hash commit with the planned job identity
before dispatch. A timeout retains the same pending record; subsequent recovery
reuses it without advancing the counter or rebuilding the payload. Reserved
attempts remain discoverable after a process crash. Confirmation records the run
ID, and a delayed failure cannot overwrite it or a later attempt. Managed
recovery retains its existing source reservation and historical sink settings.
Direct text retains its stable text workflow ID and persisted recovery attempt.
Python still owns the dispatch ledger and memo reconciliation; this application
metadata is preparation, not an alternative dispatch authority. The identity
endpoint also rejects a new retry identity when that job has an unresolved start.

## Durable dispatch and failures

Migration `0002` adds only `hawki_backend.workflow_operations`. Preparation and a
compare-and-set dispatch claim each commit before any Temporal RPC.
An additional unique partial index prevents different prepared jobs from
dispatching the same stable workflow ID while a prior start is unconfirmed.
Laravel's ordinary source starter also commits its complete preparation before calling the
backend; existing managed/document preparation remains fenced and committed first.
The backend database role needs SELECT, INSERT and UPDATE on this new table only,
in addition to its existing query grants. The ledger contains sensitive prepared
configuration: restrict access and backups; never print payloads.

A repeated confirmed operation returns its recorded run identity. Changed input
under the same logical operation returns 409. Source workflow IDs and nested retry
suffixes, text IDs, deletion IDs, schedule IDs, workflow/activity names, worker
inputs, reuse policies, timeout values and existing worker patch IDs are retained.
New starts carry an additive `hawki_control_operation` memo; existing histories
are neither rewritten nor replayed with new inputs.
An active legacy execution or a different prepared task is never assigned to a
new task merely because Temporal returned USE_EXISTING. Wait for it to finish or
reconcile its original application owner before submitting another operation.

A timeout or crash after the dispatch claim leaves `dispatching`/`uncertain`
evidence. Retrying the same request reconciles only an execution bearing the exact
operation memo. It never blindly redispatches an `ALLOW_DUPLICATE` start—even a
NOT_FOUND response can race a delayed RPC. Ambiguous or legacy starts and uncertain
schedule replacement require operator reconciliation; they return 502, not false
success. Do not delete the ledger or mint another operation to bypass that state.
After independently proving the original RPC cannot still arrive and no execution
was accepted, an operator can restore that ledger entry to `prepared`; the same
request can then safely claim and dispatch it. Retain audit evidence for that step.
Confirmed deletion requests continue polling/retrying the same deletion identity,
preserving failed-only reuse and verified cleanup receipts.

Responses distinguish invalid preparation (422), denied signature (401),
configuration unavailable (503), immutable/predecessor conflict (409), and
unconfirmed/unavailable control (502). Status preserves the execution state while
sanitizing failure details. Successful cancellation means requested; the separate
stop-for-deletion operation retains confirmed completion waiting. Neither logs
nor responses include raw SDK errors, authenticated bodies or secrets.

## Deployment and rollback

1. Deploy the backend adapter and additive ledger migration first, with
   `HAWKI_WORKFLOW_CONTROL_ENABLED=false`. Provision the shared HMAC secret and
   new-table privileges. Do not change the query selector.
2. Quiesce Laravel workflow dispatch/recovery entry points. Let in-flight control
   HTTP/RPC calls settle and reconcile existing ambiguous application preparation.
   Existing Temporal workflows may continue executing on their current workers.
3. Match backend namespace, workflow type, timeouts and activity queues to the
   current deployment. Enable backend control, then deploy the Laravel client
   (`HAWKI_WORKFLOW_CONTROL_URL=http://hawki_backend`) and the bridge release that
   returns 410 for all old `/temporal/*` controls. Resume dispatch only after all
   application replicas have switched. Never overlap old and new dispatch owners.
4. Exercise signed source/text/recovery, status, cancellation and managed deletion
   in staging. The existing old/new patch-branch tests stay required. Captured
   production-history replay and live dispatch remain separate rollout checks.

Workers do not need to drain for this epoch: their workflows, activities, payload
contracts and patch gates are unchanged. Worker upgrades involving those contracts
still follow their existing compatibility/drain procedures.

To roll back, quiesce dispatch again, settle/reconcile every uncertain ledger
operation, then restore the matching previous Laravel **and bridge** releases.
Disable backend control before resuming the old controller. Keep the ledger and
worker versions; do not downgrade/drop tables or alter active histories. There is
no automatic bridge fallback. No production migration or activation is performed
by this implementation.
