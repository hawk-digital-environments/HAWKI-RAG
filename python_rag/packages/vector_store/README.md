# HAWKI vector store

The Qdrant adapter reads and writes vector points and chunk payloads in scoped
collections. This package defines the values, request and response mapping,
and transport retries used by the adapter.
Retrieval ranking and fallback strategies remain application-owned. It has no
dependency on the graph-store package.

Callers define the reader or writer port required by their own application.
Construct `QdrantHTTP` only in an adapter or composition root.
Vectors and their chunk payloads are stored in Qdrant; graph entities and
relationships are outside this package's ownership.

## Tests

From `python_rag`, run `uv run --group test --package hawki-vector-store pytest
packages/vector_store/tests`.
