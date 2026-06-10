# Python RAG Service

This directory contains the FastAPI bridge, vector and graph ingestion logic,
query pipeline, command-line ingestion helpers, and local reranker code.

## Test Command

Run the lightweight Python characterization suite from the repository root:

```bash
make python-test
```

The target uses the current requirements-file layout and does not require a
Python packaging migration:

```bash
PYTHONPATH=python_rag python -m unittest discover -s python_rag/tests -p 'test_*.py'
```

## Runtime Output

The service writes runtime/cache data under directories such as
`python_rag/rag_storage`, `python_rag/public`, `python_rag/shared`, and Python
`__pycache__` folders. These are generated artifacts and should not be committed.
