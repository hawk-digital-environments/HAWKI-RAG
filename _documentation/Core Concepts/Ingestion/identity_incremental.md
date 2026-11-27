# Identity & Incremental Ingestion

**Source identity** ties revisions to the same logical document. A
**content hash** tells the indexer whether the document's bytes changed.
An **idempotency key** identifies one submission. Reuse it when retrying
identical input and use a new key when submitting changed content or metadata.

## Identity rules

Each source type has a stable way of identifying a document. Web pages use their normalized URL, while files may also include their relative artifact path when necessary to distinguish them. Uploaded files without an HTTP URL receive an identity based on their source and artifact path. For historical direct text, the dataset and `external_document_id` identify
the source, and the stable `document.md` artifact determines the indexed
document ID. Its `source_url` is descriptive. Inline text submitted through
`/v1/documents` uses a managed document ID. Replace it through the document API. During preparation, the system also verifies or generates a SHA-256 content hash from the actual document content.

## Document Update Detection

Qdrant is the source of truth for deciding whether a document is new, unchanged, or changed. If the identity is new, the document is indexed normally. If the identity and content hash are unchanged, normal vector and graph processing can be skipped. If the identity is the same but the content has changed, new embeddings are created and the previous vectors are replaced; graph data is also replaced after successful extraction.

Documents missing from a later submission are **not automatically removed**.
Use scoped deletion for those documents. Internal graph-only indexing can
update graph data without updating vectors, but it does not automatically
remove stale graph facts. Incremental indexing uses the authorized document
identity and store evidence. Runtime ownership comes from active records.
If Qdrant cannot be read reliably, the indexing operation may fail.

## How text ingestion handles retries

For historical text ingestions run by `IngestTextWorkflow`, the indexer checks the stored chunk points in Qdrant to determine whether a previous attempt finished indexing the whole document. This check runs whenever the indexing activity executes, including retries after a provider error, a failed Qdrant write, or an interrupted
worker. Recovery uses the same embedding model, chunking rules, and storage settings.

Replaying an HTTP submission with the same idempotency key returns its recorded
status. The completion check runs when the indexing activity executes. See [Identity, replay, and retries](../../Reference/direct_text_ingestion.md#indexing-identity-and-deletion) for the distinction between an HTTP replay and workflow recovery. After writing all chunk points, the indexer adds completion markers to their metadata. These markers identify the document content and expected set of chunks. On a retry, the indexer checks that every expected point exists and carries the matching completion markers. When that check succeeds, it reuses the existing
vectors. Missing points or completion markers cause it to process the document again.

For example, with the same model, chunking rules, and batch size throughout, a document produces 100 chunks. The first batch writes 64 points, and the next batch fails because Qdrant becomes temporarily unavailable. After Qdrant recovers, Temporal retries the indexing activity. The text is unchanged, but only 64 of the
expected 100 points are stored, so the indexer processes the document again. Stable chunk IDs allow writes to update existing points and insert missing ones.

If writing the completion markers fails after all points have been stored, the indexing activity reports a failure. A later retry checks the stored points and markers again to determine whether indexing is complete. For a fully indexed document whose content is unchanged, changes to integration metadata cause the indexer to refresh the stored metadata and completion markers while reusing the existing embeddings.

## System configuration and setup

For websites and uploaded files, the incremental planner compares document
content hashes and can skip unchanged documents after a configuration change.
Use a fresh target or controlled cleanup and rebuild to apply new indexing rules
to existing content.

Historical direct-text completion checks cover the document content and expected chunk
points. Applying a new embedding model or changed chunking rules to existing
data requires a planned rebuild.

See [Runtime options and system configuration](../../Operations/5_environment_db_queue.md#runtime-options-and-system-configuration)
for when settings take effect, and
[Embedding migration](./chunking_embeddings.md#embedding-migration) for the rebuild steps.

