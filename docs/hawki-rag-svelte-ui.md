# HAWKI-RAG Svelte UI Boundary

This document describes the browser experience added on top of the existing
Laravel and RAG services. The current implementation uses Svelte islands mounted
by Laravel/Vite, not a SvelteKit router.

## Product Worlds

| World | Browser route | Job |
| --- | --- | --- |
| User World | `/hawki-rag` | Student and teacher entry point for chat, sources, favorites, spaces, and study mode. |
| Operator World | `/admin` | Admin/developer entry point for ingestion, datasets, graph, analytics, and repair. |
| Graph World | `/neo4j-graph-explorer` | Live Neo4j/Qdrant graph exploration with the Svelte walkway and technical Cytoscape drawer. |
| Health/Repair | `/pipeline-health` and `/health/system-gate` | System diagnosis, blocking repair overlay, and troubleshoot progress states. |

## User Routes

| Route | Current target | Status |
| --- | --- | --- |
| `/hawki-rag` | Svelte experience hub | Ready |
| `/hawki-rag/chats` | `/hawki-rag-playground` | Ready |
| `/hawki-rag/chats/{chatId}` | `/hawki-rag-playground?chat={chatId}` | Ready as an alias |
| `/hawki-rag/spaces` | Svelte experience hub | Planned product surface |
| `/hawki-rag/spaces/{spaceId}` | Svelte experience hub | Planned product surface |
| `/hawki-rag/spaces/{spaceId}/sources` | `/datasets?space={spaceId}` | Ready as a source-browser alias |
| `/hawki-rag/spaces/{spaceId}/favorites` | Svelte experience hub | Planned product surface |
| `/hawki-rag/spaces/{spaceId}/study` | Svelte experience hub | Planned product surface |

## Admin Routes

| Route | Current target | Status |
| --- | --- | --- |
| `/admin` | Svelte experience hub | Ready |
| `/admin/pipeline` | `/pipeline-controller` | Ready |
| `/admin/datasets` | `/datasets` | Ready |
| `/admin/graph` | `/neo4j-graph-explorer` | Ready |
| `/admin/analytics` | Svelte experience hub | Planned product surface |
| `/admin/health-repair` | `/pipeline-health` | Ready |

## Code Map

| File | Job |
| --- | --- |
| `routes/web_ui.php` | Browser pages and UI-facing route aliases. |
| `routes/internal_api.php` | Sanctum-protected internal API routes for scripts, Bruno, and service clients. |
| `routes/health.php` | Separate health/monitoring/repair boundary. |
| `resources/views/hawki-rag.blade.php` | Blade shell for the Svelte experience hub. |
| `resources/js/hawki-rag-experience.js` | Vite entry that mounts the Svelte experience and the global troubleshoot button. |
| `resources/js/svelte/apps/HawkiRagExperience.svelte` | Product-level route map for user and operator worlds. |
| `resources/views/hawki-rag-playground.blade.php` | Blade shell for the Svelte retrieval console. |
| `resources/js/hawki-rag-playground.js` | Vite entry that mounts the Svelte retrieval console. |
| `resources/js/svelte/apps/HawkiRagPlayground.svelte` | Query composer, evidence board, graph facts, and live RAG signals. |
| `resources/js/svelte/apps/GraphExplorerPage.svelte` | Graph explorer Svelte page shell. |
| `resources/js/svelte/apps/GraphWalkway.svelte` | Creative path/walkway visualization for graph exploration. |
| `resources/js/svelte/apps/SystemTroubleshooter.svelte` | Troubleshoot button and progress/check state panel. |

## Core Service Boundary

| Service | Meaning |
| --- | --- |
| Retrieval | RAG-Anything style retrieval over Qdrant. |
| Graph Retrieval | Qdrant plus Neo4j entity/relation retrieval. |
| HAWKI-RAG-PRO | Future Proxy Pointer retrieval path for faster lookups. |
| Analytics | Future graph/topic/source analysis surfaces. |
| Health/Repair | RAG bridge, Qdrant, Neo4j, pipeline, workers, and storage diagnosis. |

## Implementation Rules

- Keep Laravel as the server-side route owner while the migration is island-based.
- Mount Svelte from Vite entry files under `resources/js`.
- Keep existing operational pages alive until a Svelte page fully replaces them.
- Put browser page routes in `routes/web_ui.php`.
- Put token-protected service/API routes in `routes/internal_api.php`.
- Keep health and repair routes in `routes/health.php`.
- Mark planned product routes clearly instead of hiding unfinished features.
- Do not copy UI/backend code from non-permissive or unclear-license projects.

## Local Check

```bash
npm run build
php artisan test --filter=HawkiRagExperienceRouteTest
```

If the host checkout has `public/build` linked to an unavailable container
directory, use this compile-only check:

```bash
npm run build -- --outDir /tmp/hawki-rag-build --emptyOutDir
```
