# Document UI section removal

1. **Component:** all four panels lived in
   `resources/js/svelte/apps/DatasetsDashboardPage.svelte`. Their DOM bindings and
   rendering helpers lived in `resources/js/datasets-dashboard.js`.
2. **Files changed in this task:** those two files,
   `tests/js/document-sections.test.js`, and this report. Complete panels were
   removed, including headings, containers, counts, tables, preview/JSON output,
   empty states and section-specific retry controls. Header copy now describes
   the remaining page. Existing document information, status, dataset, evidence,
   search, Open/Delete actions and backend download support remain intact.
3. **Dead frontend code removed:** preview, related-job, task-history and
   ingestion-history DOM bindings/renderers; metadata rendering; their counters
   and clearing code; retry helpers/requests used only by those tables; the
   failure-state helper used only by those retry controls. There were no
   feature-exclusive GET requests or timers. Dataset/document detail requests and
   the shared polling remain necessary for the surviving page.
4. **Backend retained:** no backend file or API was changed in this task.
   Metadata, markdown/extracted content, document downloads, pipeline tasks,
   history, recovery and log capabilities remain available. Authentication and
   stored data were untouched.
5. **Frontend tests:** **36 passed**, including checks that all four headings and
   their containers/bindings are absent and remaining controls are connected.
   `node --check resources/js/datasets-dashboard.js` passed. No standalone
   lint/typecheck script is configured in package.json.
6. **Build/browser:** `npm run build` and frontend Docker build passed; existing
   bundle-size/Browserslist warnings remain. Only the frontend container was
   recreated. Isolated headless Chrome verified the actual `/documents` DOM:
   all four panels absent, detail/status/evidence/search/actions retained.
   Screenshot `/tmp/rawki-document-sections.png` confirms the page closes
   naturally without empty cards. Test/build logs use the
   `/tmp/rawki-document-sections-` prefix. No data mutations were required.
7. **Diff:** `git diff --check` passed. Nothing staged or committed. Earlier
   worktree changes were preserved.
8. **Git status:** the final full worktree status follows, including prior work.

FOUR DOCUMENT UI SECTIONS REMOVED — UNCOMMITTED

