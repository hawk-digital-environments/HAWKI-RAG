# HAWKI pipeline callbacks

Python workers use this HTTP client to send signed pipeline events to FastAPI.
The client signs the serialized body and retries delivery according to the
callback policy. Temporal execution and external job requests stay in their
respective workers and clients.

## Tests

From `python_rag`, run `uv run --group test --package hawki-pipeline-callbacks
pytest packages/pipeline_callbacks/tests`.
