---
sidebar_position: 1
slug: /
title: HAWKI RAG Documentation Portal
---

# HAWKI RAG Documentation Portal

<div className="hero">

Start with the prerequisites and installation, then explore the architecture,
ingestion, retrieval, and operations guides.

[Start with Requirements](./Getting%20Started/1_requirements.md)

</div>

![HAWKI RAG Screen](assets/HAWKI_RAG_Screen.png)

## Overview

HAWKI RAG turns websites, uploaded files, and submitted text into searchable,
dataset-scoped evidence. Laravel owns the control and security plane, Python
performs ingestion and retrieval, and Temporal coordinates durable work.
Qdrant stores searchable content and vectors; optional Neo4j enrichment adds
graph facts. Retrieve passages for another application or generate an answer
grounded in those passages.

## Read in Order

<div className="grid-cards">

- <span className="grid-icon">✔️</span> __1. Requirements__
  Hardware, software, ports, and platform prerequisites.
  [Open chapter](./Getting%20Started/1_requirements.md)

- <span className="grid-icon">🚀</span> __2. Installation__
  First-time secrets, startup, health verification, and a smoke test.
  [Open chapter](./Getting%20Started/4_installation_zero_to_up.md)

- <span className="grid-icon">🛠️</span> __3. Run HAWKI RAG__
  Everyday startup, lifecycle, logging, and external-tool commands.
  [Open chapter](./Getting%20Started/2_setup.md)

- <span className="grid-icon">🏠️</span> __4. Architecture__
  The system overview and detailed ingestion and query flows.
  [Open chapter](./Getting%20Started/3_introduction_architecture.md)

- <span className="grid-icon">💾</span> __5. Environment & Configuration__
  Settings, service consumers, restart requirements, and data impact.
  [Open chapter](./Operations/5_environment_db_queue.md)

- <span className="grid-icon">👨‍🍳</span> __6. Ingestion__
  Document identity, chunking, embeddings, graph enrichment, and recovery.
  [Open chapter](./Operations/6_ingestion_embeddings.md)

- <span className="grid-icon">📚️</span> __7. MCP Query Search Contract__
  Authenticated input, trusted scope, and normalized MCP output.
  [Open chapter](./Reference/mcp_query_search_contract.md)

- <span className="grid-icon">🗺️</span> __8. Repository Map__
  Find the Laravel/Python implementation and tests for a change.
  [Open chapter](./Reference/8_repo_map.md)

</div>

## Explore by Topic

| I want to… | Read |
|---|---|
| Understand how a query finds evidence | [Query & Retrieval](./Core%20Concepts/query_retrieval.md) |
| Understand who can access a dataset | [Authorization & Dataset Scope](./Core%20Concepts/authorization_dataset_scope.md) |
| Understand vector, graph, and application state | [Storage](./Core%20Concepts/storage.md) |
| Inspect workflows, queues, and callbacks | [Temporal Operations](./Operations/temporal_operations.md) |
| Diagnose a failed or incomplete run | [Monitoring](./Operations/monitoring.md), [Troubleshooting](./Operations/troubleshooting.md), and [Recovery](./Operations/ingestion_recovery.md) |
| Integrate an application | [REST APIs](./Reference/rest_apis.md) and [Direct Text Ingestion](./Reference/direct_text_ingestion.md) |
| Contribute a change | [Testing](./Developer/testing.md) and [Architecture Rules](./Developer/architecture_rules.md) |

## Quick Start

Follow [Installation](./Getting%20Started/4_installation_zero_to_up.md) to prepare
the environment and secrets. Once configured:

```bash
make up-core
make health
```

Run the [direct-text smoke test](./Getting%20Started/2_setup.md#direct-text-smoke-test)
to verify ingestion and retrieval.

## Documentation Ownership

**Getting Started** takes you from prerequisites to daily use. **Core Concepts**
explains behavior and storage boundaries. **Operations** covers configuration,
Temporal, diagnostics, and recovery. **Reference** owns wire contracts and the
repository map. **Developer** covers tests and architecture rules.

Examples describe this checkout. Environment defaults mean the supplied
`.env.example` unless a page explicitly identifies a code fallback.
