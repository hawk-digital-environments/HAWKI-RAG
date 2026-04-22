# P1 Document Persistence Foundation

This P1 slice introduces only SQL persistence foundations for document ingest state:

- `documents`: source metadata and top-level lifecycle status for each ingested document.
- `document_processing_state`: one row per `(document_id, stage)` to track pipeline stage state.
- `document_chunks`: normalized chunk storage linked to `documents`.

## Intended P1 Usage

1. Create a `documents` row when a file or source record is accepted.
2. Call `InitializeDocumentPipelineState` once (safe to call multiple times) to seed stage rows:
   - `convert`
   - `chunk`
   - `embed`
   - `graph_extract`
   - `index_vector`
   - `index_graph`
3. Store generated text chunks in `document_chunks`.

## Boundaries Kept In This Slice

- No RabbitMQ publishers/consumers
- No event-driven orchestration
- No graph tables
- No vector tables
- No embeddings persisted in SQL

## TODO (Future Phases)

- Finalize tenant/application/heap table naming and enforce canonical foreign keys where required.
- Revisit `documents(heap_id, checksum_sha256)` uniqueness strategy if database engine strategy changes.

