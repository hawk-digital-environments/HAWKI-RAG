# Python RAG test layout

# Live direct-text E2E

Run every command below from the RAWKI repository root in your VS Code
terminal.

## 1. Start Docker

Start the RAWKI stack and confirm its containers are running.

```bash
make up-core
docker compose ps
```

## 2. Create the token

Create a Laravel Sanctum token for local user ID. When prompted, choose
that user and give the token a name such as `direct-text-e2e`. The token must
have exactly the `rag:text-ingest` ability.

```bash
docker exec -it hawki_rag_app \
  php artisan user:token --abilities=rag:text-ingest
```

Copy the generated token.

## 3. Grant dataset access

Grant user [X] ingest access to the existing active `direct-text-e2e`
dataset. The argument order is
`dataset:grant-ingest <dataset_id> <user_id>`.

```bash
docker exec -it hawki_rag_app \
  php artisan dataset:grant-ingest direct-text-e2e [USER_ID]
```

## 4. Export the dataset

Set the dataset ID in the current terminal.

```bash
export RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID=direct-text-e2e
```

## 5. Export the token

Read the token without writing it to shell history. Paste it when the terminal
waits, then press Enter.

```bash
read -s RAWKI_INTEGRATION_TEXT_INGEST_TOKEN
export RAWKI_INTEGRATION_TEXT_INGEST_TOKEN
echo
```

These exports disappear when you open a new terminal.

## 6. Check both values

Confirm the token is present without printing it, and confirm the dataset ID.

```bash
[ -n "$RAWKI_INTEGRATION_TEXT_INGEST_TOKEN" ] \
  && echo "TOKEN=set" \
  || echo "TOKEN=EMPTY"
echo "DATASET=$RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID"
```

Expected output:

```text
TOKEN=set
DATASET=direct-text-e2e
```

## 7. Run direct-text E2E

Run the focused direct-text test in Python `3.13.14` on the
`rawki_default` Docker network.

```bash
docker run --rm \
  --network rawki_default \
  -v "$PWD/python_rag:/work:ro" \
  -v hawki-uv-cache:/root/.cache/uv \
  -w /work \
  -e RAWKI_INTEGRATION_REQUIRED=1 \
  -e RAWKI_INTEGRATION_TEXT_INGEST_TOKEN="$RAWKI_INTEGRATION_TEXT_INGEST_TOKEN" \
  -e RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID="$RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID" \
  -e RAWKI_INTEGRATION_INGEST_TIMEOUT=600 \
  -e RAWKI_INTEGRATION_LARAVEL_URL=http://hawki_rag_app \
  -e RAWKI_INTEGRATION_QDRANT_URL=http://qdrant:6333 \
  -e UV_PROJECT_ENVIRONMENT=/tmp/hawki-e2e-venv \
  -e UV_LINK_MODE=copy \
  python:3.13.14-slim-bookworm \
  sh -lc '
    pip install --quiet --no-cache-dir uv &&
    uv run \
      --frozen \
      --package hawki-workflow-worker \
      --with pytest \
      pytest \
        -p no:cacheprovider \
        -c pytest.ini \
        -vv \
        -ra \
        --tb=short \
        tests/end_to_end/integration/test_direct_text_ingestion.py
  '
```

The test should finish with `PASSED`.

## 8. Run all E2E tests

After the focused test passes, run the complete live E2E directory.

```bash
docker run --rm \
  --network rawki_default \
  -v "$PWD/python_rag:/work:ro" \
  -v hawki-uv-cache:/root/.cache/uv \
  -w /work \
  -e RAWKI_INTEGRATION_REQUIRED=1 \
  -e RAWKI_INTEGRATION_TEXT_INGEST_TOKEN="$RAWKI_INTEGRATION_TEXT_INGEST_TOKEN" \
  -e RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID="$RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID" \
  -e RAWKI_INTEGRATION_INGEST_TIMEOUT=600 \
  -e RAWKI_INTEGRATION_LARAVEL_URL=http://hawki_rag_app \
  -e RAWKI_INTEGRATION_QDRANT_URL=http://qdrant:6333 \
  -e UV_PROJECT_ENVIRONMENT=/tmp/hawki-e2e-venv \
  -e UV_LINK_MODE=copy \
  python:3.13.14-slim-bookworm \
  sh -lc '
    pip install --quiet --no-cache-dir uv &&
    uv run \
      --frozen \
      --package hawki-workflow-worker \
      --with pytest \
      pytest \
        -p no:cacheprovider \
        -c pytest.ini \
        -vv \
        -ra \
        --tb=short \
        tests/end_to_end/integration
  '
```

## Coverage and locked CI

```bash
uv run --frozen --group test pytest services/hawki_bridge/tests \
  --cov=hawki_bridge --cov-report=term-missing

uv lock --check

uv sync --locked --group test --extra cpu \
  --package hawki-bridge \
  --package hawki-workflow-worker \
  --package hawki-scraper-worker \
  --package hawki-converter-worker \
  --package hawki-indexer-worker

PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
  uv run --locked --no-sync pytest -c pytest.ini -m "not integration"

UV_PROJECT_ENVIRONMENT=.venv-reranker \
  uv sync --locked --group test --package hawki-reranker --extra cpu

PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 \
UV_PROJECT_ENVIRONMENT=.venv-reranker \
  uv run --locked --no-sync pytest -c pytest.ini \
  services/hawki_reranker/tests
```

CI uses the lockfile and runs the reranker in its isolated uv environment.

Production images copy member `src/` trees rather than member `tests/` trees, so
the co-located tests are not installed into runtime images.
