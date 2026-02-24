# 3. Introduction & Architecture (For Absolute Beginners)

## What This System Is (Plain Words)
- A question-answering machine: you feed it documents; it reads them; it answers questions using only those documents.
- Built with **Laravel (PHP)** plus **Python** services, all inside **Docker** so you avoid complex local installs.

## What “RAG” Means (Analogy)
- Imagine a librarian with two superpowers:
  - She **finds** the right paragraphs in all books she has read (retrieval).
  - She **writes** a new answer using those paragraphs (generation).
- RAG = Retrieval Augmented Generation. First find; then write.

## How This Project Implements RAG
- **Vector DB (Qdrant):** Stores meanings of text as numbers (“embeddings”).
- **Graph DB (Neo4j):** Stores entities/relationships extracted from text.
- **Models (Ollama):** Runs `bge-m3` for embeddings; `llama3.1:8b` to write answers; `llama3.2:1b` for fast graph tasks.
- **Bridge (Python FastAPI):** Ingests documents, chunks text, makes embeddings/graph, saves to Qdrant/Neo4j.
- **Reranker (Python):** Improves ordering of search results.
- **RAG API (Python):** Answers questions by retrieving from Qdrant/Neo4j and calling Ollama.
- **Laravel App:** Web/API frontend; shows ingest status; proxies queries.
- **Nginx:** Single public entry on port 8080; everything else stays inside the Docker network.

## Full System Architecture (Step-by-Step Flow)
1) User opens browser → hits Nginx on **http://localhost:8080**.
2) Nginx routes to Laravel inside `hawki_rag_app`.
3) Laravel may:
   - Call **RAG API** (`raganything_api_gpu`) for answers.
   - Call **Bridge** (`hawki_rag_bridge`) for ingest health or file listings.
4) RAG API retrieves from **Qdrant** (vectors) and may rerank via **hawki_rag_rerank**.
5) RAG API asks **Ollama** for generation using retrieved text.
6) Optional: graph extraction stored in **Neo4j**.
7) Responses go back to Laravel → user.

## Key Concepts Explained
- **Embedding:** A list of numbers representing the meaning of text so similar texts are close together.
- **Vector Database:** A database that can search by “closeness” of embeddings (Qdrant).
- **Graph Database:** Stores nodes (entities) and edges (relationships) for richer queries (Neo4j).
- **Queue:** Background job system (RabbitMQ optional; Laravel DB queue default).
- **Container:** A packaged mini-computer image; Docker runs many containers together.

