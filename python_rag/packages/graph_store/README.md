# HAWKI graph store

This package owns RAWKI's graph persistence boundary and Neo4j adapter. It
contains graph contracts, reader and writer ports, scoped Cypher requests,
response parsing, and managed-transaction behavior. Graph cleanup, retrieval
ranking, and RAG-hit projection remain application-owned. It has no dependency
on the vector-store or text-processing packages.

Callers should type application dependencies against `GraphReader` or
`GraphWriter`. Construct `Neo4jGraph` only in an adapter or composition root.
Entities and relationships are stored in Neo4j; embeddings and vector search
are outside this package's ownership.

## Neo4j Driver 6 error behavior

`Session.execute_read()` and `Session.execute_write()` own transaction retries;
the application does not wrap them in another retry loop. Transaction callbacks
must therefore be idempotent and must materialize results with `consume()`,
`single()`, or `list(...)` before returning.

Optional graph reads return an empty result only for Neo4j availability
failures. Other server and driver failures propagate to their handling
boundary. An explicitly configured database falls back to the default database
only when Neo4j reports `Neo.ClientError.Database.DatabaseNotFound`.

## Tests

From `python_rag`, run `uv run --group test --package hawki-graph-store pytest
packages/graph_store/tests`. The `integration/` category requires live Neo4j.
