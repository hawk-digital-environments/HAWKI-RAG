# HAWKI worker runtime

Workers use this package to load Temporal settings, create activity
executors, configure logging, and send heartbeats. Heartbeat contents and retry policy belong
to the activity or client that understands the operation. FastAPI callbacks
and external HTTP job polling live in independently installable packages.

## Tests

From `python_rag`, run `uv run --group test --package hawki-worker-runtime pytest
packages/worker_runtime/tests`.
