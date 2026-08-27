# HAWKI RAG text processing

This package contains deterministic, side-effect-free text operations: Markdown
cleanup, chunking, term extraction, tag normalization, and prompt/output safety
rules. It performs no model, network, database, filesystem, or environment I/O.

## Tests

From `python_rag`, run `uv run --group test --package hawki-rag-text pytest
packages/text_processing/tests`.
