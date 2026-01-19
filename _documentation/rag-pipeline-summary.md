# RAWKI RAG Pipeline Documentation Summary (Scrape to Retrieval)

## Purpose and Intended Audience
This document explains the complete end-to-end system, from web scraping through retrieval and answer generation. It is intended for non-technical leaders who need a clear, accurate description of what the system does, how it ensures quality, and how it is operated at scale.

Key goals:
- Provide an auditable, reproducible path from source websites to retrieved answers.
- Emphasize usability for operators and analysts.
- Highlight the measures that improve accuracy and safety.

## Executive Overview
The system is a full retrieval-augmented generation pipeline. It:
1. Crawls and captures web content (pages, images, PDFs).
2. Converts and stores that content in structured files and optional database tables.
3. Prepares the content for retrieval by chunking text and generating embeddings.
4. Stores embeddings in a vector index and optionally extracts knowledge graph triplets.
5. Retrieves and ranks the most relevant content for a user query.
6. Produces grounded answers that cite sources and follow safety constraints.

## System Usability (Operator View)
Operator usability is built into the pipeline:
- A one-command pipeline exists for end-to-end crawl + conversion + ingest.
- Progress, status, and summaries are written to stable, human-readable log files.
- The system supports resume mode, dry-run estimation, and safe deletion.
- Retrieval supports query filters and tunable relevance controls.

## End-to-End Flow (High-Level)
```
Website URLs
  -> Crawler Pipeline (validation, execution, storage)
  -> Local crawl dataset (pages, assets, metadata)
  -> PDF conversion (PDF -> Markdown)
  -> Ingestion scan + metadata normalization
  -> Chunking + embeddings
  -> Vector index (Qdrant) + optional graph (Neo4j)
  -> Query -> retrieval -> reranking -> grounded answer
```

## 1) Scraping and Content Acquisition

### Entry Points
Operators can start a crawl using:
- `scraper:scrape` (interactive or scripted crawl execution).
- `crawl:and-convert` (crawl plus PDF conversion).
- `rawki:pipeline` (crawl + convert + ingest in a single workflow).

### Validation and Configuration
Before crawling, the request is validated for:
- URL validity and business rules.
- Host filtering (forbidden hosts list).
- Crawl configuration integrity (page limits, output directory, labels).

### Execution
The crawler:
- Runs a Node.js Crawlee-based system.
- Streams progress events.
- Produces deterministic output folders per job ID and URL hash.
- Supports restart/continue logic on repeated runs.

### File Storage Layout (Crawl Outputs)
Each crawl job writes to a folder structure that separates job-level data and per-page data:
- Job-level reports:
  - `job_state.json`
  - `summary.json`
  - `urls_tracking.json`
  - `url_chunks/` (chunked URL lists)
- Per-page data (stored in nested folders using a URL hash):
  - `content.md` (page content)
  - `data.json` (page metadata)
  - `images/` (downloaded image files)
  - `pdfs/` (downloaded PDFs, if any)

Folder layout uses a hash prefix split for large directory sets:
```
<job_id>/<hash_prefix>/<url_hash>/
```

### Database Storage (Optional)
Scraped pages can also be stored in a relational table to support structured querying and access controls. Each record includes:
- Page URL, title, content hash, URL hash.
- Domain and subdomain.
- Language, images, PDFs, publication dates.
- Fetch time and response status.
- Access level flag (public/internal/restricted/confidential).

This provides a governance layer for auditing and downstream access policies.

### PDF Conversion
After a crawl, PDF files are converted into Markdown to ensure they are searchable in the same way as HTML pages. The conversion output is placed alongside crawl outputs and used in later ingestion scans.

### Finalization and Data Integrity Checks
After crawl completion:
- URL lists are reconciled against stored records.
- Missing database rows are backfilled from disk.
- Job status is finalized and cached entries are cleared.

## 2) Dataset Preparation and Ingestion

### Folder Scanning
The ingestion scanner:
- Walks the crawl output directory.
- Detects page folders with `.md`, `.txt`, or `.json` content.
- Associates converted PDF outputs with the original source URLs.

### Document Identity
Each document receives a deterministic ID:
- Derived from the source URL or fallback path.
- Hashed with SHA-1 for consistent deduplication across runs.

### Metadata Normalization
The ingest payload includes:
- Title, URL(s), canonical URL, and source URL.
- Language and publication/updated timestamps.
- Content hash, URL hash, and content length.
- Page images and PDFs.
- Tags and keywords (from metadata or extracted from content).
- Source format and ingestion timestamp.

### Tag Extraction
Tags are resolved in this order:
1. Explicit tags or keywords from metadata.
2. Fallback keyword extraction from the page text.

### Chunking Rules (Quality and Recall)
Text is chunked before embedding:
- Target chunk size (default 3200 characters).
- Overlap (default 100 to 250 characters, depending on ingress path).
- Chunk boundaries prefer paragraph breaks to reduce semantic splits.

Chunking improves retrieval accuracy by preserving context and enabling finer-grained matches.

### Graph Extraction (RAG-Anything Engine)
Triplet extraction uses the RAG-Anything engine exclusively:
- Every chunk can yield `(subject, relation, object)` triplets.
- Triplets are written into Neo4j with a `doc_id` link for traceability.
- Entity and relation representations are also embedded and stored in Qdrant alongside text chunks.

This mirrors the paper’s dual-graph idea: explicit structural relations live in Neo4j, while dense vectors for
entities/relations/chunks live in Qdrant for semantic retrieval.

### Batch and Resume Controls
Ingestion supports:
- Batch size (default 64 documents per request).
- Resume state tracking (saved doc IDs to skip already processed items).
- Dry-run mode to estimate chunk counts and storage impact without indexing.

## 3) Embeddings and Storage

### Embedding Providers
The system supports multiple embedding providers:
- Local embeddings via Ollama (default).
- External provider (GWDG) for hosted inference.

Embedding configuration includes model selection and expected vector dimension.

### Vector Storage (Qdrant)
Chunks, entities, and relations are embedded and stored as vector points:
- Each text chunk becomes one point with a vector and payload.
- Each extracted entity becomes one point (component_type = entity).
- Each extracted relation becomes one point (component_type = relation).
- The collection is created or validated against the embedding dimension.
- Similarity distance defaults to cosine but is configurable.

The ingest summary includes:
- Total points ingested.
- Total documents processed and skipped.
- Current point counts in the collection.

### Graph Storage (Neo4j, Optional)
When enabled:
- Triplets are extracted per chunk using RAG-Anything.
- Triplets are inserted into a graph database with doc_id linkage.
- Post-ingest stats include entity counts and relationship types.

This enables entity-aware retrieval augmentation and cross-document reasoning.

## 4) Retrieval Workflow

### Safety and Input Normalization
Queries are filtered for unsafe or malicious patterns:
- Prompt-injection and jailbreak heuristics.
- Disallowed token checks.
- Excess length trimming.

Blocked queries are rejected with a clear safety message.

### Embedding and Retrieval
Steps:
1. The user query is rewritten and normalized by the LLM into a structured form:
   - rewritten_query, high_level_keys, low_level_keys, entity_terms, modality_hints
2. The rewritten query is embedded.
3. The vector index is searched using one of several strategies:
   - Basic dense search.
   - High-recall search with higher HNSW search depth.
   - Optimized search with similarity thresholds and tag filters.
4. Optional metadata filters (tags, access levels, or other payload fields) are applied.

### Hybrid Retrieval (RAG-Anything)
Retrieval combines two complementary pathways:
- **Structural navigation** (Neo4j): entity matching and hop expansion to gather related facts.
- **Semantic similarity** (Qdrant): dense search over chunks, entities, and relations.

The two candidate pools are fused with weighted scoring (semantic + structural) before reranking.

### Reranking
Results are reranked using:
- None (leave original vector scores).
- Cosine reranker (re-embed snippets and compare).
- External reranker service.
- Jina reranker.

Mix mode can blend original vector scores with reranker scores for stability.

### Iterative Retrieval (Quality Boost)
If initial results are weak or the query indicates multi-step intent:
- The system expands the query with extracted tags and key terms.
- A second retrieval pass is executed with higher recall.
- Results from both passes are merged and reranked.

### Context Assembly
Before answer generation:
- A fixed number of top documents is selected.
- Content is truncated to a token budget.
- Snippets are sanitized to remove unsafe text.
- The system records which sources were trimmed.
- Each snippet is annotated with component type (chunk/entity/relation) and format (text/markdown).

### Graph Augmentation
When available:
- Entities are extracted from the query and retrieved snippets.
- Related graph facts are fetched and included as supporting context.

### Grounded Answer Generation
When answer generation is enabled:
- The response is constrained to the retrieved context.
- Each claim must be backed by a cited source (title and URL).
- If evidence is insufficient, the system declines to answer.

Output is passed through safety filters before delivery.

### Retrieval Output Structure
Each query returns:
- Ranked hits with payloads and similarity scores.
- Knowledge graph facts (if enabled).
- Generated answer (if enabled).
- Retrieval diagnostics (context tokens used, iteration flags, sanitization details).

## 5) Accuracy Controls and Quality Assurance

Accuracy and robustness are built into multiple layers:
- Validation before crawl execution.
- Consistent content hashing and URL normalization.
- Chunking with overlap to preserve context.
- Tag extraction and metadata enrichment for better filtering.
- Similarity thresholds to reject weak matches.
- Reranking options for semantic precision.
- Hybrid retrieval fusion to balance structural relevance and semantic similarity.
- Iterative retrieval for complex or multi-part questions.
- Token-limited context assembly to prevent truncation bias.
- Safety filters to block injection and remove unsafe outputs.

## 6) Operational Monitoring and Logs

Key operator files:
- Crawl logs: `storage/logs/crawler/`
- Ingest logs: `storage/logs/ingest_progress.log`
- Ingest status: `storage/logs/ingest_status.json`
- Ingest summary: `storage/app/public/ingest_summary.json`
- Pipeline status: `storage/logs/pipeline_status.json`

These files provide:
- Progress tracking for long-running jobs.
- Audit trails for what was ingested and when.
- Post-run summaries for verification and reporting.

## 7) Usability Features for Government-Scale Operations

Built-in capabilities that support institutional use:
- Clear separation of stages for accountability.
- Resume-safe ingestion for large crawls.
- Optional database storage for access control and auditing.
- Configurable retrieval strategies (precision vs recall).
- Explicit quality controls for data safety and traceability.
- Deterministic logging for post-hoc investigation.

## 8) Configuration Highlights (Operational Knobs)

Key tunable settings include:
- Crawl limits (`max-pages`, output directory, label).
- Chunk size and overlap.
- Embedding provider and model.
- Vector similarity distance and collection name.
- Graph engine (RAG-Anything enforced).
- Fusion weights and structural hop depth for hybrid retrieval.
- Reranker mode and mix weighting.
- Context token and document limits.
- Graph extraction engine.

These controls allow the system to be tuned for either rapid coverage or high precision, depending on mission needs.

## 9) Limitations and Responsible Use

Known constraints and mitigations:
- Retrieval quality depends on crawl coverage and source quality.
- Embedding quality depends on chosen models and language coverage.
- Graph extraction quality depends on text clarity and extraction engine.
- The system enforces safety filters, but human review remains essential for high-stakes decisions.

## 10) Traceability Summary

The system guarantees end-to-end traceability:
- Each answer is linked back to retrieved sources.
- Each chunk is linked to the source document and URL.
- Each document is linked to the crawl job and stored metadata.

This creates a defensible, auditable pipeline suitable for public-sector use where provenance and reliability matter.

## 11) Example Story: Regulation Deadline Question (with Estimated Timing)

### Scenario
A staff member needs a specific detail about a new-semester regulation and its submission deadline, which is documented only inside a PDF that was previously crawled and converted into searchable text.

### User Question (Example)
“What is the exact deadline for submitting the new semester regulation forms, and where is it stated?”

### Step-by-Step Pipeline (Query to Answer)
1) **Query safety and normalization (≈ 3–8 ms)**  
   The system sanitizes the user query, checks for unsafe patterns, and trims excessive length.

2) **Query embedding (≈ 25–80 ms)**  
   The sanitized query is converted into a vector representation using the configured embedding model.

3) **Vector retrieval in Qdrant (≈ 15–60 ms)**  
   The system searches the vector index for the top matching chunks, filtering by metadata if needed (e.g., tags like “regulation” or “semester”).

4) **Reranking (≈ 25–120 ms)**  
   A reranker reorders the retrieved chunks to prioritize the most relevant passages, especially if the first pass is weak or ambiguous.

5) **Iterative retrieval (optional, ≈ 20–90 ms)**  
   If the first pass is weak, the system expands the query with extracted terms and performs a second retrieval to boost recall.

6) **Graph lookup in Neo4j (≈ 10–40 ms)**  
   The system optionally fetches related entities and relationships (e.g., “submission deadline → dates → semester regulations”) for additional context.

7) **Context assembly (≈ 10–25 ms)**  
   The top passages are compressed into a token-limited context. If the PDF has a precise deadline sentence, it is preserved and highlighted.

8) **Final answer generation (≈ 400–1500 ms)**  
   The grounded answer is produced by the configured language model using the retrieved context. Citations include the PDF title and source URL.

### Estimated End-to-End Latency
- **Typical total (single pass, local models): 500–1200 ms**
- **Worst case (rerank + iterative pass + graph + remote model): 900–2500 ms**

These numbers assume the system is running on standard production hardware with warm caches, the index is healthy, and the vector store is not under heavy load.

### Result (What the user sees)
The user receives a concise answer:
- The exact deadline is stated clearly.
- The answer cites the PDF title and the source URL.
- If the deadline is missing or contradictory, the system answers “not found in sources.”

### Why this is trustworthy for government use
- The answer is grounded in stored documents, not in model memory.
- The cited PDF is the authoritative source, preserving auditability.
- The system records retrieval and safety diagnostics for review.
