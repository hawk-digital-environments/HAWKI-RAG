# Authorization & Dataset Scope

External applications authenticate their users. They call FastAPI with service
JWTs issued for registered clients. FastAPI verifies the signature against the
HTTPS JWKS and checks issuer, audience, lifetime, tenant, client, and scopes.

Dataset creation and the authenticated `/v1/readiness` endpoints require
`rag:datasets:manage`. Creation uses the tenant from the service JWT and
issues a one-time ingest grant token for the new dataset.

1. Create the dataset with `POST /v1/datasets`.
2. Redeem the token as `grant_token` at
   `POST /v1/datasets/{dataset_id}/ingest-grants/self` with `rag:ingest`.
   This grants the calling service ingest access to that active dataset.
3. Index content with `rag:ingest` and the ingest grant. To obtain a new
   service query grant, call `POST /v1/datasets/{dataset_id}/query-grants/self`
   with both `rag:query` and `rag:datasets:manage`. The dataset's Qdrant
   collection must be available and contain at least one indexed point.

The query self-grant endpoint can also reconcile an existing grant. With
`rag:query` and an existing grant on the active dataset, the service can replay
the request even if the Qdrant catalog is unavailable. This path still requires
the backend to read the dataset and grant records.

Queries require `rag:query` and the exact query grant. Ingestion requires
`rag:ingest` and the exact ingest grant. Other dataset-scoped routes apply their
own policy. For example, graph reads require query access and dataset
retirement uses the management scope within the caller's tenant.

The dataset record selects the Qdrant collection, Neo4j namespace, embedding
model and provider, and graph capability. The API checks the grant before
retrieval or ingestion. Caller-supplied metadata has no effect on tenant or
storage selection.

[Runtime deployment and security configuration](../Operations/python_runtime.md).
