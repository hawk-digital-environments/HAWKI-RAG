# HAWKI RAG V2 Coverage

This document tracks the canonical V2 surface now expected across HAWKI RAG.

## Canonical terms

- `Tenant`
- `Application`
- `Adapter`
- `Heap`
- `Document`
- `Corpus`
- `Chunk`
- `Group`
- `Metadata`
- `Filter`

## Current coverage

| Area | Status | Notes |
| --- | --- | --- |
| Tenant | Exists | First-class API and relational model. |
| Application | Exists | First-class API and bearer-oriented actor model. |
| Heap | Exists | Canonical API surface is `/api/heaps`; heap terminology should be used in UI and docs. |
| Document | Exists | Document payloads include heap and corpus references. |
| Corpus | Exists | First-class API surface with document linkage. |
| Chunk | Partial | Operational in the vector layer; not directly exposed as a Laravel model. |
| Group | Exists | First-class API surface and membership management. |
| Metadata | Partial | Exposed on heaps and documents; reserved-keyword enforcement remains future work. |
| Filter | Partial | Gateway-built filters are active; richer boolean grammar remains future work. |
| Search API | Exists | Canonical search routes are `/api/search`, `/api/search/chunks`, `/api/search/chunks/grouped`, and `/api/search/documents`. |

## Architecture summary

- Laravel is the gateway for request authentication, actor resolution, and filter construction.
- Python is the search executor.
- Qdrant stores vectorized chunk payloads.
- Optional permission-graph enforcement remains available through SpiceDB or OpenFGA.

## Compatibility boundary

Compatibility routes and compatibility-era storage symbols still exist internally for migration safety. They should not be treated as the canonical product language in new docs, new UI surfaces, or new API guidance.
