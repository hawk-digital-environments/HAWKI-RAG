# HAWKI vector store

This package owns RAWKI's vector persistence boundary and Qdrant adapter. It
contains vector contracts, reader and writer ports, request construction,
response parsing, transport retry policy, and scoped collection behavior.
Retrieval ranking and fallback strategies remain application-owned. It has no
dependency on the graph-store package.

Callers should type application dependencies against `VectorReader` or
`VectorWriter`. Construct `QdrantHTTP` only in an adapter or composition root.
Vectors and their chunk payloads are stored in Qdrant; graph entities and
relationships are outside this package's ownership.

## Tests

From `python_rag`, run `uv run --group test --package hawki-vector-store pytest
packages/vector_store/tests`.
