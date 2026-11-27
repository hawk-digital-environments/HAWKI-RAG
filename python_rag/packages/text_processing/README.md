# HAWKI RAG text processing

This package cleans up Markdown, splits it into chunks, extracts terms,
normalizes tags, and checks prompt and output text. These operations are
deterministic and run entirely on the supplied text, with no model, network,
database, filesystem, or environment access.

## Tests

From `python_rag`, run `uv run --group test --package hawki-rag-text pytest
packages/text_processing/tests`.
