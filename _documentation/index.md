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

HAWKI-RAG is a containerized Retrieval-Augmented Generation platform built to turn crawled website content into usable intelligence. It combines a Laravel operator layer (UI + API) with a FastAPI pipeline for ingestion, retrieval, and optional graph enrichment, so operations stay simple while the backend remains capable.

Crawled Markdown files are processed through the RAG-Anything flow, chunked and embedded with Ollama, indexed in Qdrant for semantic retrieval, and optionally enriched into graph triplets in Neo4j for relation-aware context. The result is a practical knowledge engine that blends speed (vector search) with structure (graph reasoning) in one operational stack.

## Read in Order

<div className="grid-cards">

- <span className="grid-icon">✔️</span> __1. Requirements__
  Hardware, software, ports, and platform prerequisites before you run anything.
  [Open chapter](./Getting%20Started/1_requirements.md)

- <span className="grid-icon">🛠️</span> __2. Setup with Makefile__
  The core operational commands for networking, startup, health checks, and logs.
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

- <span className="grid-icon">👨‍💻</span> __7. Commands Catalogue__
  Practical command reference with purpose, expected output, and common fixes.
  [Open chapter](./Operations/7_commands_catalogue.md)

- <span className="grid-icon">📚️</span> __8. RagSearcher Triplets Update__
  Interface contract and runtime behavior for triplet-aware retrieval in MCP, Laravel, and Python.
  [Open chapter](./Operations/8_ragsearcher_triplets_update.md)

- <span className="grid-icon">🗺️</span> __9. Repository Map__
  Folder-by-folder orientation for Laravel, Python, Docker, volumes, and logs.
  [Open chapter](./Reference/9_repo_map.md)

</div>

## Quick Start

```bash
make network
make up-core
make test-services
```
