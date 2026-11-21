# HAWKI RAG Svelte frontend

Svelte owns browser page markup. The active shell is `frontend/index.html` and
`frontend/main.js`; `npm run build` uses `vite.frontend.config.js`. The same
existing components and DOM runtimes remain in place. nginx serves the built
shell at the existing page URLs. The frontend build and development server require only Node; PHP/Blade and the legacy build target have been removed.

`playground/urls.js` maps existing UI operation names onto Python `/v1` paths
through `/ui-api/v1/`. `api.js` supplies the browser CSRF marker and adapts Python
error envelopes. The server-side UI gateway injects its externally issued service
JWT. The explicit local profile opens on loopback without browser login or
credentials; other deployments delegate management approval to HAWKI. Browser
JavaScript receives no service credential, signing key, or human session. Direct HAWKI service traffic
uses `/v1/*` with its own JWT.

## Structure

- `apps/`: page-level or global Svelte islands mounted from existing Vite entry files.
- `components/`: reusable HAWKI RAG components with no route assumptions.
- `components/ui/`: low-level primitives with no business logic.
- `stores/`: shared cross-component state classes using Svelte 5 runes.
- `types/`: shared TypeScript payload and UI types.
- `util/`: framework-agnostic helpers.

## Migration Rule

Keep endpoint contracts stable while moving page structure, shared navigation,
forms, panels, and state rendering into Svelte. Existing DOM runtimes may remain
temporarily only when they are loaded after a Svelte page shell mounts.

Current product islands:

- `apps/HawkiRagExperience.svelte`: `/admin` minimal admin landing page.
- `apps/HawkiRagPlayground.svelte`: `/hawki-rag-playground` retrieval console.
- `apps/SystemTroubleshooter.svelte`: global Health/Repair troubleshoot button.
- `apps/GraphExplorerPage.svelte`: Neo4j graph explorer page shell.
- `apps/DatasetsDashboardPage.svelte`: `/datasets` page shell.
- `apps/PipelineControllerPage.svelte`: `/pipeline-controller` page shell.
- `apps/PipelineHealthDashboardPage.svelte`: `/pipeline-health` page shell.

Route ownership:

- Active browser pages and aliases are selected in `frontend/main.js`.
- Active APIs are the existing FastAPI routers under `python_backend`.
- `/v1/health` is public liveness; authenticated readiness powers the health dashboard.
