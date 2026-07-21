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

The dependency target builds `mineru==3.4.4+rawki.1` locally from the official
MinerU 3.4.4 wheel after verifying its SHA-256 digest. The local version carries
the small pipeline compatibility patch needed for Transformers 5.14.1 and
narrows MinerU's `core` extra to that pipeline backend. MinerU's local VLM and
Gradio extras are intentionally unsupported by this image. The generated
third-party wheel is kept outside the repository, and the same guarded build
runs inside the bridge image.

On Linux ARM64, `pip check` currently reports
`nvidia-cusparselt-cu13 0.8.1 is not supported on this platform`. Torch 2.13.0
pins that package, whose ARM binary is valid but whose internal wheel tag says
`sbsa` instead of `aarch64`. This is an upstream metadata false positive; do
not force a different CUDA library version around Torch's exact pin.

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
