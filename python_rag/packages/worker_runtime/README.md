# HAWKI worker runtime

This package contains only Temporal activity-executor construction, heartbeat
delivery, retry-delay values, and worker logging setup. Laravel callbacks and
external HTTP job polling live in independently installable packages.

## Tests

From `python_rag`, run `uv run --group test --package hawki-worker-runtime pytest
packages/worker_runtime/tests`.
