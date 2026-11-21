# HAWKI RAG documentation

The active runtime is FastAPI + Python workers + the existing Svelte frontend.
HAWKI owns human authentication. Service JWT/JWKS, tenant grants, signed callbacks,
permanent-job-v2 and historical identity reservations remain mandatory.

- [Current deployment and architecture](Operations/python_runtime.md)
- [Python/frontend testing](Developer/testing.md)
- [Frozen public-schema history](History/public_schema.md)

Follow the deployment guide for configuration, startup, readiness and data
preservation requirements.
