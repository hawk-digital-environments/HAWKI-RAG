# HAWKI vector store

This package owns RAWKI's vector persistence boundary and Qdrant adapter. It
contains vector contracts, reader and writer ports, request construction,
response parsing, retry policy, and scoped collection behavior. It has no
dependency on the graph-store package.

Callers should type application dependencies against `VectorReader` or
`VectorWriter`. Construct `QdrantHTTP` only in an adapter or composition root.
Vectors and their chunk payloads are stored in Qdrant; graph entities and
relationships are outside this package's ownership.
