# Authorization & Dataset Scope

HAWKI owns human authentication. FastAPI authenticates registered service identities using asymmetric JWT verification, HTTPS JWKS, issuer/audience/lifetime, tenant, client registry and scopes. It does not implement a mirrored human account system.

Authorization requires both the action's coarse scope and an exact grant on the active dataset for that tenant/service. Management scope does not implicitly grant ingestion. Dataset creation issues a one-time bootstrap capability; ingest self-grant redemption remains explicit. Query grants require real indexed content and the applicable service policy.

The canonical dataset determines Qdrant collection, Neo4j namespace, embedding provider/model and graph capability. Caller metadata cannot select physical storage scope or another tenant. Authorization precedes retrieval/context generation and ingestion side effects.

The browser uses the UI gateway's existing service identity, with exact Origin checks for mutations. The explicit local mode trusts only loopback ingress; other deployments delegate management approval to HAWKI. Provider credentials and service JWTs never enter frontend assets.

[Runtime deployment and security configuration](../Operations/python_runtime.md).
