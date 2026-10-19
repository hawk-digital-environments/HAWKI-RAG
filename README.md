# HAWKI RAG

HAWKI RAG provides retrieval and grounded answers over managed content. Laravel
owns the public control and security plane; Python performs ingestion and
retrieval, with Temporal orchestrating durable work.

<img width="2720" height="992" alt="HAWKI RAG Logo green" src="https://github.com/user-attachments/assets/af606f07-185b-4204-bcb8-8db1e8a58766" />

## Start here

1. Check [Requirements](_documentation/Getting%20Started/1_requirements.md).
2. Follow [Installation](_documentation/Getting%20Started/4_installation_zero_to_up.md)
   for secrets, startup, initial health, user creation, and a smoke test.
3. Use [Run HAWKI RAG](_documentation/Getting%20Started/2_setup.md)
   for everyday commands and the differences between local, image, and server modes.

The Docker stack supplies the application, Python roles, stores, workflow
engine, and local model runtime. Website/file ingestion also uses external
crawler/converter projects; direct-text ingestion does not require them.

## Retrieval Modes

Fast retrieval is the default in the Playground and query API (`fast_mode: true`).
It uses vector retrieval without graph expansion or KG facts. Select **Deep
retrieval**, or send `fast_mode: false`, to include available dataset graph data.
Deep retrieval remains vector-only when the dataset has no ready graph-enabled
sources or active graph-enabled document outputs.

Graph ingestion is off by default for file uploads and crawler tasks. Enable it
explicitly when ingesting content that should populate Neo4j. Direct text
integration always skips graph ingestion-in this release-. Enabling graph ingestion does not
change the default retrieval mode.

## Documentation

| Task | Guide |
|---|---|
| Understand the system | [Introduction & Architecture](_documentation/Getting%20Started/3_introduction_architecture.md) |
| Configure models, stores, endpoints, or secrets | [Environment & Configuration](_documentation/Operations/5_environment_db_queue.md) |
| Understand indexed content | [Ingestion](_documentation/Operations/6_ingestion_embeddings.md) |
| Understand retrieval and generation | [Query & Retrieval](_documentation/Core%20Concepts/query_retrieval.md) |
| Understand deployment access boundaries | [Authorization & Dataset Scope](_documentation/Core%20Concepts/authorization_dataset_scope.md) |
| Integrate text or query clients | [REST APIs](_documentation/Reference/rest_apis.md), [Direct Text Ingestion](_documentation/Reference/direct_text_ingestion.md), [MCP](_documentation/Reference/mcp_query_search_contract.md) |
| Diagnose operations | [Monitoring](_documentation/Operations/monitoring.md), [Troubleshooting](_documentation/Operations/troubleshooting.md), [Recovery](_documentation/Operations/ingestion_recovery.md) |
| Change code | [Repository Map](_documentation/Reference/8_repo_map.md), [Testing](_documentation/Developer/testing.md) |

Browse the [documentation site](https://rag.hawki.info) or the
[source homepage](_documentation/index.md). Release history is in
[the changelog](_changelog/README.md).
