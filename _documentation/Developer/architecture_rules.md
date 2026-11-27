# Architecture Rules

FastAPI authorizes requests, resolves dataset scope, and records operations.
Workers execute activities and write through typed store adapters. They report
status through signed callbacks. Keep HTTP validation, domain decisions, and
storage or network access in their respective layers.

Before making changes, check the service identity, tenant, action scope, and any
dataset grant required by the route. Resolve physical storage from the dataset
record. Replay the same mutation with its recorded operation identity and
workflow input. Recovery that recrawls a URL or replaces a managed revision
prepares a successor generation and workflow identity. Check the expected
generation and enforce writer fences before cleanup or recovery. Keep dispatch
and dataset authority with the owning Python service.

Shared packages define the contracts used by services. Services supply their own
dependencies. Keep query configuration and credentials in deployment settings.
When changing a wire contract, test its consumers against the new contract and
any supported older inputs.

Keep frozen schema and wire fixtures independent of the implementation under
test. Retained database rows and reserved archive identities provide historical
evidence. Active ownership comes from Python operational records.

Update the affected member READMEs and guides. See [testing](testing.md) and
[runtime deployment](../Operations/python_runtime.md).
