# HAWKI RAG – HAWKI’s Retrieval Stack

HAWKI RAG is the customised retrieval deployment used in the HAWKI project. It keeps the
Laravel application and FastAPI bridge you already know, but rebrands the end-user
experience and Docker stack, highlighting the combo of **Qdrant** + **Neo4j** + the
HAWKI RAG pipeline.

HAWKI RAG is designed for fast retrieval over crawled HAWKI content. By default it uses
`bge-m3` for embeddings and `llama3:8b` / `llama3.1:8b` for grounded answers.
