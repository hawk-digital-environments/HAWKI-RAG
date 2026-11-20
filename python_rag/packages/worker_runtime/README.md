# HAWKI worker runtime

This package contains only Temporal activity-executor construction, connection
settings, and worker logging setup. Heartbeat contents and retry policy belong
to the activity or client that understands the operation. Laravel callbacks
and external HTTP job polling live in independently installable packages.

## Tests

From `python_rag`, run `uv run --group test --package hawki-worker-runtime pytest
packages/worker_runtime/tests`.
