# HAWKI-RAG Svelte Migration

Svelte is introduced as Laravel/Vite islands. Laravel keeps routing, Blade keeps
page shells, and Svelte owns rich interactive UI surfaces one component at a
time.

## Structure

- `apps/`: page-level or global Svelte islands mounted from existing Vite entry files.
- `components/`: reusable HAWKI-RAG components with no route assumptions.
- `components/ui/`: low-level primitives with no business logic.
- `stores/`: shared cross-component state classes using Svelte 5 runes.
- `types/`: shared TypeScript payload and UI types.
- `util/`: framework-agnostic helpers.

## Migration Rule

Move one self-contained UI surface at a time. Keep existing endpoint contracts
stable and mount Svelte from the current Laravel Vite entries until a whole page
is ready to become a Svelte app.

First island: `apps/SystemTroubleshooter.svelte`, mounted from
`resources/js/health-gate.js`.

Current product islands:

- `apps/HawkiRagExperience.svelte`: `/hawki-rag` and `/admin` product route map.
- `apps/HawkiRagPlayground.svelte`: `/hawki-rag-playground` retrieval console.
- `apps/SystemTroubleshooter.svelte`: global Health/Repair troubleshoot button.
- `apps/GraphExplorerPage.svelte`: Neo4j graph explorer page shell.
- `apps/GraphWalkway.svelte`: creative graph path/walkway visualization.

Route ownership:

- Browser pages and aliases stay in `routes/web_ui.php`.
- Token-protected service routes stay in `routes/internal_api.php`.
- Health, monitor, and repair routes stay in `routes/health.php`.

The full route boundary is documented in `docs/hawki-rag-svelte-ui.md`.
