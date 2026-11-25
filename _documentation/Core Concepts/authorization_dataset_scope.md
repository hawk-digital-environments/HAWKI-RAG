# Authorization & Dataset Scope

HAWKI owns human authentication. FastAPI authenticates registered service identities using asymmetric JWT verification, HTTPS JWKS, issuer/audience/lifetime, tenant, client registry and scopes. It does not implement a mirrored human account system.

Authorization requires both the action's coarse scope and an exact grant on the active dataset for that tenant/service. Management scope does not implicitly grant ingestion. Dataset creation issues a one-time bootstrap capability; ingest self-grant redemption remains explicit. Query grants require real indexed content and the applicable service policy.

The canonical dataset determines Qdrant collection, Neo4j namespace, embedding provider/model and graph capability. Caller metadata cannot select physical storage scope or another tenant. Authorization precedes retrieval/context generation and ingestion side effects.

External applications handle human authentication and call HAWKI-RAG with their own scoped service JWTs. The issuer registers each client and the backend verifies tenant-specific dataset grants.

[Runtime deployment and security configuration](../Operations/python_runtime.md).
