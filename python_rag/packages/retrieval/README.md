# hawki-rag-retrieval

Shared RAG query/retrieval package for RAWKI.

This package contains the core retrieval logic extracted from the bridge service,
including:

- Query execution and orchestration
- Semantic and lexical retrieval
- Graph/Neo4j integration
- Reranking
- Fusion and ranking policies
- Context assembly and answer generation

## Usage

```python
from hawki_rag_retrieval import QueryDependencies, execute_authorized_query
from hawki_rag_retrieval.adapters import QdrantReader, Neo4jReader, rerank_hits
from hawki_model_providers.factory import create_model_provider

dependencies = QueryDependencies(
    vector_search_factory=QdrantReader,
    graph_search=Neo4jReader(),
    resolve_model_provider=create_model_provider,
    rerank_hits=rerank_hits,
)

response = execute_authorized_query(request, dependencies=dependencies)
```