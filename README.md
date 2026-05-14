# HAWKI RAG – HAWKI’s Retrieval Stack

HAWKI RAG is the customised retrieval deployment used in the HAWKI project. It keeps the
Laravel application and FastAPI bridge you already know, but rebrands the end-user
experience and Docker stack, highlighting the combo of **Qdrant** + **Neo4j** + the
HAWKI RAG pipeline.

HAWKI RAG is designed for fast retrieval over crawled HAWKI content. By default it uses
`bge-m3` for embeddings and `llama3:8b` / `llama3.1:8b` for grounded answers.
<img width="2720" height="992" alt="HAWKI RAG Logo green" src="https://github.com/user-attachments/assets/af606f07-185b-4204-bcb8-8db1e8a58766" />

## RabbitMQ Ingestion Worker

Converted-document event consumption is available as an additive worker layer:

- Docs: [docs/rag_ingestion_worker_rabbitmq.md](docs/rag_ingestion_worker_rabbitmq.md)
- DB/ops commands: [docs/db_cookbook.md](docs/db_cookbook.md)
- Worker entrypoint: `php artisan rag:rabbit-ingestion-worker`
- Python remains the FastAPI RAG bridge for embeddings, Qdrant, Neo4j, RAG-Anything/LightRAG, and `/ingest`.

## Neo4j Graph Explorer Indexes

The HAWKI playground graph explorer uses Neo4j directly for entity search and graph expansion.
Create this fulltext index for fast entity lookup:

```cypher
CREATE FULLTEXT INDEX entity_name_fulltext IF NOT EXISTS
FOR (n:Entity)
ON EACH [n.name, n.entity_id];
```

Semantic graph search uses the existing RAG semantic retrieval as a fallback. If graph nodes
also carry embedding vectors in Neo4j, add a vector index matching the embedding dimensions,
for example for `bge-m3`:

```cypher
CREATE VECTOR INDEX entity_embedding_vector IF NOT EXISTS
FOR (n:Entity)
ON (n.embedding)
OPTIONS {
  indexConfig: {
    `vector.dimensions`: 1024,
    `vector.similarity_function`: 'cosine'
  }
};
```
