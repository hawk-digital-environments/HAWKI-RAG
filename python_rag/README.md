# HAWKI RAG Python workspace

One Python **3.13.14** uv workspace and one lockfile contain six production
service roles and ten reusable packages. Laravel owns public authorization and
application PostgreSQL records. Python performs RAG work and reports application
state through signed callbacks.

Start with [runtime architecture](../_documentation/Getting%20Started/3_introduction_architecture.md)
and the [workspace dependency map](../_documentation/Reference/8_repo_map.md#actual-workspace-dependencies).

| Concern | Documentation owner |
|---|---|
| Service/member layout | [Services](services/README.md), [Repository Map](../_documentation/Reference/8_repo_map.md) |
| Shared package contracts | [Packages](packages/README.md) |
| Install/run/rebuild | [Run HAWKI RAG](../_documentation/Getting%20Started/2_setup.md) |
| Model/store/endpoint configuration | [Configuration](../_documentation/Operations/5_environment_db_queue.md) |
| Workflow names, queues, callbacks, compatibility | [Temporal Operations](../_documentation/Operations/temporal_operations.md) |
| Deterministic suites and isolated reranker environment | [Testing](../_documentation/Developer/testing.md) |
| Live integration tests | [Test README](tests/README.md) |
| Ownership rules | [Architecture Rules](../_documentation/Developer/architecture_rules.md) |

The bridge is a read-only data-plane bridge with query/graph reads and health,
plus Temporal control operations. It has no Qdrant/Neo4j ingestion write route.
The indexer calls its application logic directly. Python does not issue SQL
against Laravel tables; current Compose still injects shared dotenv variables,
including database credentials, into Python containers.

Production images include allowlisted member source and exclude tests/caches.
Local mode mounts Laravel source only. CPU/GPU variants remain the same service
roles; reranker and indexer model dependencies require separate uv environments.
