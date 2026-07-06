# HAWKI RAG Svelte UI Boundary

This document describes the browser experience added on top of the existing
Laravel and RAG services. The current implementation uses Svelte page components
mounted by Laravel/Vite through one thin Blade document shell, not a SvelteKit
router.

## Operator Surface

| Surface | Browser route | Job |
| --- | --- | --- |
| Operator World | `/admin` | Admin/developer entry point for ingestion, heaps, graph, analytics, and repair. |
| Graph World | `/neo4j-graph-explorer` | Live Neo4j/Qdrant graph exploration with the Svelte graph shell and Cytoscape drawer. |
| Health/Repair | `/pipeline-health` and `/health/system-gate` | System diagnosis, blocking repair overlay, and troubleshoot progress states. |

## Operator Routes

| Route | Current target | Status |
| --- | --- | --- |
| `/admin` | Svelte experience hub | Ready |
| `/admin/pipeline` | `/pipeline-controller` | Ready |
| `/admin/heaps` | `/heaps` | Ready |
| `/admin/datasets` | `/heaps` | Compatibility alias |
| `/admin/graph` | `/neo4j-graph-explorer` | Ready |
| `/admin/search` | `/hawki-rag-search` | Ready |
| `/admin/retrieve` | `/hawki-rag-search` | Compatibility alias |
| `/admin/analytics` | Svelte experience hub | Planned product surface |
| `/admin/health-repair` | `/pipeline-health` | Ready |

## Code Map

| File | Job |
| --- | --- |
| `routes/web_ui.php` | Browser pages and UI-facing route aliases. |
| `routes/internal_api.php` | Human-authenticated and bearer-app internal API routes for scripts, Bruno, and service clients. |
| `routes/health.php` | Separate health/monitoring/repair boundary. |
| `resources/views/svelte-page.blade.php` | Shared Blade document shell for CSRF, base path metadata, Vite tags, and initial JSON config. |
| `resources/js/hawki-rag-experience.js` | Vite entry that mounts the Svelte experience and the global troubleshoot button. |
| `resources/js/svelte/apps/HawkiRagExperience.svelte` | Product-level route map for operator tabs. |
| `resources/js/hawki-rag-playground.js` | Vite entry that mounts the Svelte search console. |
| `resources/js/svelte/apps/HawkiRagPlayground.svelte` | Query composer, evidence board, graph facts, and live RAG signals. |
| `resources/js/svelte/apps/GraphExplorerPage.svelte` | Graph explorer Svelte page shell. |
| `resources/js/svelte/apps/DatasetsDashboardPage.svelte` | Heap Browser page shell. |
| `resources/js/svelte/apps/PipelineControllerPage.svelte` | Pipeline Controller page shell. |
| `resources/js/svelte/apps/PipelineHealthDashboardPage.svelte` | Pipeline Health page shell. |
| `resources/js/svelte/components/DashboardHeader.svelte` | Shared dashboard header and route navigation. |
| `resources/js/svelte/apps/SystemTroubleshooter.svelte` | Troubleshoot button and progress/check state panel. |

## Core Service Boundary

| Service | Meaning |
| --- | --- |
| Search | RAG-Anything style search over Qdrant. |
| Graph Search | Qdrant plus Neo4j entity/relation search. |
| HAWKI-RAG-PRO | Future Proxy Pointer search path for faster lookups. |
| Analytics | Future graph/topic/source analysis surfaces. |
| Health/Repair | RAG bridge, Qdrant, Neo4j, pipeline, workers, and storage diagnosis. |

## Implementation Rules

- Keep Laravel as the server-side route owner while browser markup lives in Svelte page components.
- Mount Svelte from Vite entry files under `resources/js`.
- Keep existing endpoint contracts stable while replacing page markup and DOM runtimes incrementally.
- Put browser page routes in `routes/web_ui.php`.
- Put token-protected service/API routes in `routes/internal_api.php`.
- Keep health and repair routes in `routes/health.php`.
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
