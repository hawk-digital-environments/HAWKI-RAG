# Python RAG Service

This directory contains the FastAPI bridge, vector and graph ingestion logic,
query pipeline, command-line ingestion helpers, and local reranker code.

## Test Command

Run the deterministic Python contract and API suite from the repository root:

```bash
make python-test
```

Install runtime and test dependencies with `make python-deps` from a Python
3.11 environment, matching the bridge image. The test target uses pytest so
both `unittest.TestCase` scenarios and module-level pytest functions are
collected:

```bash
PYTHONPATH=python_rag python -m pytest -c python_rag/pytest.ini -m "not integration"
```

When the corresponding services are reachable, run the opt-in live suites:

```bash
make python-integration
make provider-test
```

See [`tests/README.md`](tests/README.md) for the API flows, feature categories,
endpoint coverage, and the Laravel/Python authorization boundary.

## Runtime Output

The service writes runtime/cache data under directories such as
`python_rag/rag_storage`, `python_rag/public`, `python_rag/shared`, and Python
`__pycache__` folders. These are generated artifacts and should not be committed.
