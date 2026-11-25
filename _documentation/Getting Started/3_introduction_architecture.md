# Architecture

```mermaid
flowchart LR
    Client[Private-network client with a service JWT] -->|/v1| Backend[FastAPI]
    Backend --> Postgres[PostgreSQL operational schema]
    Backend --> Temporal
    Backend --> Qdrant
    Backend --> Neo4j
    Backend --> Models[Ollama or LiteLLM]
    Temporal --> Workers[Workflow and activity workers]
    Workers -->|Signed callbacks| Backend
    Workers --> Sources[Converter and optional crawler]
    Workers --> Storage[Shared artifacts]
    Workers --> Qdrant
    Workers --> Neo4j
```

The backend verifies issuer, audience, signature, service identity, tenant,
scopes and exact dataset grants. Query and ingestion resolve their physical
store targets from authorized dataset metadata. External applications own
their human authentication and request service tokens from the configured issuer.

The backend and infrastructure listen on private Docker networks. Workers sign
the original callback bytes and submit them through the project network.
