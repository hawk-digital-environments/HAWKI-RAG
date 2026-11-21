# Direct text ingestion

`POST /v1/text-ingestions` accepts a registered service principal with `rag:ingest` and an exact active dataset ingest grant. Browser clients use `/ui-api/v1/text-ingestions`; service credentials stay in the gateway.

Supply `dataset_id`, `external_document_id`, `text`, and `content_format` according to the registered API schema, with a stable `Idempotency-Key`. Python stores immutable Markdown and durable operation identity before dispatching indexer work through Temporal. Direct text is vector-only; it does not require a crawler or converter.

## Identity, replay and retries

Read task state through the pipeline API. Use the owning text operation for deletion, preserving generation and writer fences. A lost response must reconcile the existing idempotent operation rather than invent a second source. Signed callbacks validate workflow/run/source identity and publication evidence.

The typed input, normalization and artifact rules live under `python_backend/src/hawki_backend/text_ingestion`; frozen parity fixtures are test-only independent expectations. See [deployment](../Operations/python_runtime.md) and [testing](../Developer/testing.md) for disposable acceptance and cleanup audits.
