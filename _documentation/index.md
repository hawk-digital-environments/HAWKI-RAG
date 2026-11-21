# HAWKI RAG documentation

The active runtime is FastAPI + Python workers + the existing Svelte frontend.
HAWKI owns human authentication. Service JWT/JWKS, tenant grants, signed callbacks,
permanent-job-v2 and historical identity reservations remain mandatory.

- [Current deployment and architecture](Operations/python_runtime.md)
- [Python/frontend testing](Developer/testing.md)
- [Historical orphan custody and graph acceptance](Operations/historical_orphan_graph_acceptance.md)
- [Frozen public-schema history](History/public_schema.md)

Laravel/PHP has been removed. Older guides are retained with historical notices;
follow the current deployment guide rather than their retired commands.
