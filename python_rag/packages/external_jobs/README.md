# HAWKI external jobs

This package owns the generic HTTP start/status polling protocol shared by the
scraper and converter adapters. It does not own Temporal, worker logging,
Laravel callbacks, or service-specific payload interpretation.

## Tests

From `python_rag`, run `uv run --group test --package hawki-external-jobs pytest
packages/external_jobs/tests`.
