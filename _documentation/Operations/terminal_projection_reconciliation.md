> **Historical Laravel-era reference.** The application/runtime described below has been removed. Do not run its Laravel/PHP commands. Use the [current Python deployment guide](python_runtime.md); the original text is retained as migration history.

# Terminal pipeline stage projection reconciliation

This code-only reconciliation fix follows Epoch 15B-1. It does not authorize
Epoch 15B-2, a deployment, or any live repair. The command is discovered through
Laravel's existing `app/Console/Commands` registration.

Inspect without writing:

```bash
php artisan pipeline:reconcile-terminal-projections --json
php artisan pipeline:reconcile-terminal-projections --job-id=JOB_ID --json
```

After independent operational approval, the intended repair syntax is:

```bash
php artisan pipeline:reconcile-terminal-projections --job-id=JOB_ID --apply --evidence-ref=APPROVED_AUDIT_REFERENCE --json
```

`--apply` requires an exact job ID. Each running stage is independently checked,
locked, revalidated and committed.
Previously committed rows remain committed if another row fails. Rerunning is
safe: terminal stages are never selected or rewritten. In both dry-run and apply
modes, `candidate`, `no_candidate`, and `reconciled` return exit 0; safety `refused`
and `not_found` return exit 1. JSON and human-readable output use the same exit
contract. Dry runs remain read-only, including when safety assessment refuses.
Job IDs must be 1–191 ASCII letters/digits or `_.:-`, starting with a letter or
digit; wildcard-looking scopes refuse. Invalid scopes return `status=refused`
and unknown exact jobs return `status=not_found`, both with exit 1. An existing
job with no running stages returns `status=no_candidate` and exit 0. Eligible
inspection and successful repair return `candidate` and `reconciled` respectively.

Only `completed`/`failed` tasks, `completed`/`failed`/`skipped` jobs, and explicitly
terminal job index projections (`ready`, `failed`, `cancelled`, `deleted`) qualify.
Sources must exist, agree on ownership, and be `deleted`, `failed`, or `cancelled`.
Schedules, unresolved recovery, unresolved replacement, other execution identities,
missing ownership, malformed evidence, and unknown statuses refuse repair.
Dataset identities must be nonempty and agree across task, source, managed owner
and associated operation. Source workflow identity comes from its persisted
workflow column; its exact run must be confirmed by `metadata.temporal.run_id`
or `metadata.worker_event.run_id`. Missing evidence refuses even with a reference.
Durable `managed_document_outputs` source/job/task edges participate in document
discovery when latest pointers advance. Output identities, managed owners and
their deletion/update operations are included in the locked snapshot comparison;
conflicting ownership or unresolved associated work refuses repair.

Dispatched sources require writer protocol 1, a complete target manifest, a
closed writer gate, and positively released writers. Unknown legacy writer
state refuses repair even with a reference. This command never closes a writer
gate, releases a writer, changes output ownership, or repairs writer evidence.
Historical executions requiring separate evidence also refuse this narrow repair.

Temporal inspection reads the exact workflow/run through authenticated workflow
control. Active, unknown, mismatched, missing, or unavailable executions refuse
repair. A terminal response permits repair once the database checks pass.
Only an SDK NOT FOUND carrying a single valid typed `NotFoundFailure` from the
execution describe call enters the workflow-absence path. A typed
`NamespaceNotFoundFailure`, untyped/malformed/conflicting error details, generic
HTTP 404, authentication error, timeout or outage is unavailable.
The adapter change must be included in a separately reviewed deployment before
retention-expired repairs can use this path. Existing deployments that obscure
NOT FOUND continue to refuse mutation.

NOT FOUND does **not** prove termination or retention expiry. For that branch,
`--evidence-ref` is mandatory and is the operator's explicit assertion that an
independent audit approved retention expiry and quiescence for the exact job,
workflow and run. The referenced record must identify those identities, establish
retention expiry, verify workers/schedules and outstanding sink activity are
quiescent, and confirm that recovery/replacement cannot restart this operation.
The CLI does not fetch or authenticate an external approval system. A reference
cannot override database safety checks or an outage. Run without `--apply` first
with the same scope and reference, and review every reported reason.

Audit output identifies the stage, task, job, source, parent/index statuses,
workflow/run, Temporal classification, eligibility, reason, evidence reference,
proposed action and actual action. Successful repairs retain that evidence and a
timestamp under stage metadata `terminal_projection_reconciliation`, append a
parent-termination error, and set `status=failed`, `failed_at`, `completed_at`, and
`last_transition_at`. Task, job and source rows remain unchanged.

Future cancellation persists the terminal job and closes its running stages in
one transaction. Failure to save a stage rolls back the job projection. The
existing source fence is deliberately persisted before requesting external
cancellation; an uncertain external side effect cannot be undone by SQL rollback.

`rag:legacy-drain-status` counts the actual repaired stages. It also reports
`nonterminal_job_index_states`, so a terminal job cannot hide a running, NULL or
unknown index projection. Missing schema is unknown and blocks readiness. These
checks do not waive output cleanup, deletion, writer, history, backup, rollback,
Temporal/callback quiet-window or destructive-approval gates.
