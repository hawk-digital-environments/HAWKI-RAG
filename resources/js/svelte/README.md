# HAWKI-RAG Svelte Migration

Svelte is the owner for browser page markup. Laravel keeps routing and one thin
Blade document shell for CSRF, base-path metadata, Vite tags, and initial JSON
config.

## Structure

- `apps/`: page-level or global Svelte islands mounted from existing Vite entry files.
- `components/`: reusable HAWKI-RAG components with no route assumptions.
- `components/ui/`: low-level primitives with no business logic.
- `stores/`: shared cross-component state classes using Svelte 5 runes.
- `types/`: shared TypeScript payload and UI types.
- `util/`: framework-agnostic helpers.

## Migration Rule

Keep endpoint contracts stable while moving page structure, shared navigation,
forms, panels, and state rendering into Svelte. Existing DOM runtimes may remain
temporarily only when they are loaded after a Svelte page shell mounts.

Current product islands:

- `apps/HawkiRagExperience.svelte`: `/admin` minimal operator landing page.
- `apps/HawkiRagPlayground.svelte`: `/hawki-rag-playground` retrieval console.
- `apps/SystemTroubleshooter.svelte`: global Health/Repair troubleshoot button.
- `apps/GraphExplorerPage.svelte`: Neo4j graph explorer page shell.
- `apps/DatasetsDashboardPage.svelte`: `/datasets` page shell.
- `apps/PipelineControllerPage.svelte`: `/pipeline-controller` page shell.
- `apps/PipelineHealthDashboardPage.svelte`: `/pipeline-health` page shell.

Laravel shell:

- `resources/views/svelte-page.blade.php`: single browser document shell.

Route ownership:

- Browser pages and aliases stay in `routes/web_ui.php`.
- Token-protected service routes stay in `routes/internal_api.php`.
- Health, monitor, and repair routes stay in `routes/health.php`.
