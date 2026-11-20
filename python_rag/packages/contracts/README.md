# HAWKI RAG contracts

This package contains versioned wire models and stable Temporal identifiers
shared by the Python RAG services. It performs no filesystem, network,
database, framework, or service I/O.

## Contract domains

- `hawki_rag_contracts.pipeline` owns artifact references, ingestion payloads,
  pipeline status events, document identity rules, and Temporal names.
- `hawki_rag_contracts.retrieval` owns authorized query, query response, and
  reranker wire models.

Use those domain-qualified modules in new code. The former top-level modules,
such as `hawki_rag_contracts.ingestion`, are compatibility aliases and must not
gain new behavior.

## Tests

From `python_rag`, run `uv run --group test --package hawki-rag-contracts pytest
packages/contracts/tests`.
