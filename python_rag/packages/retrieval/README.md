# hawki-rag-retrieval

FastAPI calls this package in the backend process with an authorized dataset
scope. The package handles:

- Semantic and lexical retrieval and score fusion.
- Optional graph retrieval from Neo4j.
- Optional reranking of the leading hits. With mode `none`, the reranker
  leaves the incoming order intact.
- Context assembly and optional answer generation.

## Usage

The example assumes that the caller has already resolved the authorized
dataset scope in `request`.

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
