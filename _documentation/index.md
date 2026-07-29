---
sidebar_position: 1
slug: /
---

# HAWKI-RAG Documentation Portal

<div className="hero">
This portal is organized as a guided flow: from prerequisites to deployment, with chapter-by-chapter operational detail.

[Start with Requirements](./Getting%20Started/1_requirements.md)
</div>

![HAWKI RAG Screen](assets/HAWKI_RAG_Screen.png)

## Overview

HAWKI-RAG is a containerized, dataset-scoped retrieval-augmented generation platform that turns uploaded documents and crawled sources into grounded, searchable knowledge. Laravel provides the admin UI, canonical API, dataset management, and operational status. The Python FastAPI service owns the ML data plane, while Temporal coordinates durable scraping, conversion, ingestion, retries, cancellation, and recovery. Documents are converted to normalized Markdown and split into searchable chunks. Local Ollama creates embeddings by default, and Qdrant stores the chunk vectors and retrieval payloads. When graph ingestion is enabled, RAG-Anything coordinates the document and multimodal extraction flow, LightRAG extracts entities and relations inside that flow, and HAWKI-RAG normalizes and deduplicates the resulting dataset-scoped facts before storing them in Neo4j. At query time, Laravel supplies the caller's authorized dataset, and the Python service combines vector, lexical, and optional graph evidence. Retrieval scores are normalized across stages, duplicate chunks are removed by chunk identity, and an optional reranker orders the strongest evidence before answer generation.

## Read in Order

<div className="grid-cards">

- <span className="grid-icon">✔️</span> __1. Requirements__
  Hardware, software, ports, and platform prerequisites before you run anything.
  [Open chapter](./Getting%20Started/1_requirements.md)

- <span className="grid-icon">🛠️</span> __2. Setup with Makefile__
  Startup, lifecycle, health, logging, and maintenance commands with practical guidance.
  [Open chapter](./Getting%20Started/2_setup.md)

- <span className="grid-icon">🏠️</span> __3. Architecture__
  Beginner-friendly explanation of the full RAG system and service interactions.
  [Open chapter](./Getting%20Started/3_introduction_architecture.md)

- <span className="grid-icon">🚀</span> __4. Installation__
  Zero-to-running installation sequence with expected outputs and failure fixes.
  [Open chapter](./Getting%20Started/4_installation_zero_to_up.md)

- <span className="grid-icon">💾</span> __5. Environment, DB, Queue__
  Complete environment variable map, migrations, and queue setup details.
  [Open chapter](./Operations/5_environment_db_queue.md)

- <span className="grid-icon">👨‍🍳</span> __6. Ingestion and Embeddings__
  End-to-end ingest flow, chunking/embedding behavior, and monitoring.
  [Open chapter](./Operations/6_ingestion_embeddings.md)

- <span className="grid-icon">📚️</span> __7. MCP Query Search Contract__
  Authenticated input, trusted dataset scope, bridge payload, and normalized MCP output.
  [Open chapter](./Operations/7_ragsearcher_triplets_update.md)

- <span className="grid-icon">🗺️</span> __8. Repository Map__
  Developer map from a requested change to its Laravel/Python source and tests.
  [Open chapter](./Reference/8_repo_map.md)

</div>

## Quick Start

```bash
make up-core
make test-services
```
