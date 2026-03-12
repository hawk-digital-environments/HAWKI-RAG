---
hide:
  - toc
---

# HAWKI-RAG Documentation Portal

<div class="hero" markdown>
This portal is organized as a guided flow: from prerequisites to deployment, with chapter-by-chapter operational detail.

[Start with Requirements](1_requirements.md){ .md-button .md-button--primary }
</div>

![HAWKI RAG Screen](assets/HAWKI_RAG_Screen.png){ width="100%" }

## Overview

HAWKI-RAG is a containerized Retrieval-Augmented Generation platform built to turn crawled website content into usable intelligence. It combines a Laravel operator layer (UI + API) with a FastAPI pipeline for ingestion, retrieval, and optional graph enrichment, so operations stay simple while the backend remains capable.

Crawled Markdown files are processed through the RAG-Anything flow, chunked and embedded with Ollama, indexed in Qdrant for semantic retrieval, and optionally enriched into graph triplets in Neo4j for relation-aware context. The result is a practical knowledge engine that blends speed (vector search) with structure (graph reasoning) in one operational stack.

## Read in Order

<div class="grid cards" markdown>

- :material-list-status: __1. Requirements__

  ---

  Hardware, software, ports, and platform prerequisites before you run anything.

  [Open chapter](1_requirements.md)

- :material-wrench-cog: __2. Setup with Makefile__

  ---

  The core operational commands for networking, startup, health checks, and logs.

  [Open chapter](2_setup.md)

- :material-graph-outline: __3. Architecture__

  ---

  Beginner-friendly explanation of the full RAG system and service interactions.

  [Open chapter](3_introduction_architecture.md)

- :material-rocket-launch: __4. Installation__

  ---

  Zero-to-running installation sequence with expected outputs and failure fixes.

  [Open chapter](4_installation_zero_to_up.md)

- :material-database-cog: __5. Environment, DB, Queue__

  ---

  Complete environment variable map, migrations, and queue setup details.

  [Open chapter](5_environment_db_queue.md)

- :material-file-tree: __6. Ingestion and Embeddings__

  ---

  End-to-end ingest flow, chunking/embedding behavior, and monitoring.

  [Open chapter](6_ingestion_embeddings.md)

- :material-console-line: __7. Commands Catalogue__

  ---

  Practical command reference with purpose, expected output, and common fixes.

  [Open chapter](8_commands_catalogue.md)

- :material-source-repository: __8. Repository Map__

  ---

  Folder-by-folder orientation for Laravel, Python, Docker, volumes, and logs.

  [Open chapter](11_repo_map.md)

- :material-relation-many-to-many: __9. RagSearcher Triplets Update__

  ---

  Interface contract and runtime behavior for triplet-aware retrieval in MCP, Laravel, and Python.

  [Open chapter](12_ragsearcher_triplets_update.md)

</div>

## Quick Start

```bash
make network
make up-core
make test-services
```
