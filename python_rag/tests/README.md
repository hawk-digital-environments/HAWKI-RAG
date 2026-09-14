# Python live integration tests

[Testing](../../_documentation/Developer/testing.md) owns deterministic suites,
locked environment setup, and the isolated reranker environment. This page
covers live cross-service tests under [end_to_end/integration](end_to_end/integration).

## What the tests prove

| Test | Live dependencies and effects |
|---|---|
| [Direct text](end_to_end/integration/test_direct_text_ingestion.py) | Laravel, bridge, Temporal, indexer, shared artifacts, embedding runtime, Qdrant; creates persistent test source/task/points and verifies exact content plus ready projection |
| [Temporal dispatch](end_to_end/integration/test_temporal_ingestion.py) | Real Temporal with the production source workflow on a unique test queue, using deterministic test activities; writes workflow history, but does not test crawler/converter/model/store adapters |

The Temporal dispatch test is not the full external-tool ingestion smoke.
Use [Run HAWKI RAG](../../_documentation/Getting%20Started/2_setup.md#full-ingestion-smoke-test)
for that check.

## Direct-text setup

Start the stack and follow
[dataset/token preparation](../../_documentation/Reference/direct_text_ingestion.md#prepare-a-dataset-and-token)
for a disposable dataset. Use a token containing `rag:text-ingest` and an
explicit ingest grant; it need not contain only that ability.

From the repository root, export the test inputs without printing the token:

```bash
export RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID=docs-smoke
read -r -s RAWKI_INTEGRATION_TEXT_INGEST_TOKEN
export RAWKI_INTEGRATION_TEXT_INGEST_TOKEN
```

The test creates unique sources but does not clean them up automatically.

## Run on the Compose network

Qdrant and Temporal are Docker-internal by default. Inspect the Qdrant container
and select its Compose default network (the project prefix depends on your checkout):

```bash
docker inspect hawki_qdrant --format '{{json .NetworkSettings.Networks}}'
```

Set `HAWKI_TEST_NETWORK` to that actual network name in your terminal.
The following runner mounts the workspace read-only and installs a temporary
test environment. It requires image/package network access:

```bash
docker run --rm \
  --network "$HAWKI_TEST_NETWORK" \
  -v "$PWD/python_rag:/work:ro" \
  -v hawki-uv-cache:/root/.cache/uv \
  -w /work \
  -e RAWKI_INTEGRATION_REQUIRED=1 \
  -e RAWKI_INTEGRATION_TEXT_INGEST_TOKEN \
  -e RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID \
  -e RAWKI_INTEGRATION_INGEST_TIMEOUT=600 \
  -e RAWKI_INTEGRATION_LARAVEL_URL=http://hawki_rag_app \
  -e RAWKI_INTEGRATION_QDRANT_URL=http://qdrant:6333 \
  -e RAWKI_INTEGRATION_TEMPORAL_ADDRESS=temporal:7233 \
  -e UV_PROJECT_ENVIRONMENT=/tmp/hawki-e2e-venv \
  -e UV_LINK_MODE=copy \
  python:3.13.14-slim-bookworm \
  sh -lc '
    pip install --quiet --no-cache-dir uv==0.11.26 &&
    uv run --frozen --package hawki-workflow-worker --with pytest \
      pytest -p no:cacheprovider -c pytest.ini -vv -ra --tb=short \
      tests/end_to_end/integration/test_direct_text_ingestion.py
  '
```

For both live tests, change only the final test path to
`tests/end_to_end/integration`. Pass `TEMPORAL_NAMESPACE` if using a nondefault
namespace and `QDRANT_API_KEY` if your Qdrant deployment requires it.

`RAWKI_INTEGRATION_REQUIRED=1` makes missing dependencies fail. Without it,
unavailable infrastructure can skip tests. Read the final pytest summary;
a skip is not a passing end-to-end verification.
