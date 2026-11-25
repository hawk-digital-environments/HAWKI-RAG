# Requirements

Use Docker with Compose v2 and the Python and uv versions pinned in the backend
and worker projects. Local builds need enough RAM and disk for the chosen
models, indexes, and volumes. CUDA workers also need the NVIDIA Container
Toolkit. Node is used by the separate documentation site build.

Provision a registered service JWT issuer and a PostgreSQL backend role with
only the operational schema grants. The [runtime guide](../Operations/python_runtime.md)
covers readiness, data retention, and production image prerequisites.
