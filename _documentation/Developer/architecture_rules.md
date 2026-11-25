# Architecture Rules

FastAPI owns authorization, canonical dataset scope and durable operation state. Python workers own execution and store writes through typed adapters; signed callbacks publish application status. Keep HTTP validation, domain decisions and persistence/external clients distinct.

Authorization must precede side effects. Require service identity, tenant/action scope and exact dataset grant. Never derive physical storage from caller metadata. Keep immutable workflow input, idempotency, generation checks and writer fences consistent across retries and cleanup.

Shared packages expose small typed contracts; services compose dependencies. Keep query configuration and credentials deployment-owned. Do not add a second dispatcher or duplicate dataset authority outside the owning Python service.

Change wire contracts deliberately with consumer tests. Preserve frozen schema/wire fixtures and test-only compatibility identities; do not regenerate independent reference expectations from the implementation under test. Historical public records and archive reservations are evidence, not active Python ownership.

Update member READMEs and current guides with behavior changes. Use [testing](testing.md) and [runtime deployment](../Operations/python_runtime.md) for checks.
