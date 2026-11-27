# HAWKI RAG contracts

The backend and workers use these wire models to read each other's payloads.
The package also defines stable Temporal names and has no runtime I/O.

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
