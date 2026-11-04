"""Python-owned direct-text ingestion.

This package owns the public direct-text operation: validation, dataset
authorization, idempotency, the immutable Markdown artifact, workflow
preparation and the authoritative lifecycle state. It reuses the Epoch 8
workflow-control ledger for dispatch and does not read Laravel's ``public``
schema at runtime.
"""

from .schema import ingest_text_events, ingest_text_operations

__all__ = ["ingest_text_events", "ingest_text_operations"]
