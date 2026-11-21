# Frontend display acceptance

Verified 2026-11-21 on the current worktree. No staging or commit. Earlier
authentication and API-integration changes remain intact.

1. **Dataset theme cause:** standalone `frontend/main.js` imported the dark theme
   before two legacy page stylesheets which reset root tokens to light values.
   Dataset controls also hardcoded white backgrounds.
2. **Styling changes:** import the shared dark theme after page CSS; replace
   white/selected/destructive backgrounds in `datasets-dashboard.css` with shared
   panel, teal and red tokens. Existing layout preserved. Isolated headless Chrome
   screenshot `/tmp/rawki-display-datasets.png` confirms dark panels and controls.
3. **Missing log cause:** the canonical preview/download endpoints existed, but
   their timeline read only URL-ingestion event rows. Managed uploads have no URL
   operation IDs. Their old durable receipts retained acceptance IDs without stage
   detail. The UI exposed a separate selected-stage viewer instead of an inline
   expandable preview per stage.
4. **Log availability and API changes:** retain the same preview/download URLs.
   Signed managed callbacks now persist an allowlisted stage-event projection in
   their existing JSON receipts. The authorized timeline reads those task-owned
   managed events. No migration, global worker-log exposure or fabricated history.
   Earlier receipts lacking stage detail remain an intentional empty state.
   Shared process stdout remains server-side; these previews show real signed
   stage events, not unscoped worker stdout.
5. **Log UX:** each stage has collapsible logs, loading/error/empty text and a
   download when content exists. Expanded stages refresh with existing 3-second
   task polling; no extra timer. Text uses `textContent`. Stage state/counts derive
   from the latest persisted signed evidence; absent evidence is `not_tracked`.
6. **Old Engine source:** `parseMonitor()` in `HawkiRagPlayground.svelte` hardcoded
   `Python graph ops` for Python authority.
7. **New Engine:** the presentation helper displays **RAGAnything**. Internal
   classes, modules and protocols were not renamed.
8. **Query model:** live value **llama3.1:8b**, from validated backend
   `QuerySettings.models` (deployment query-model configuration), using the
   canonical latest dataset's provider or configured default provider.
9. **Embedding:** live value **bge-m3**, from the canonical dataset record; without
   datasets it uses the same deployment embedding default as dataset creation.
10. **Latest dataset:** **lesen**, from tenant-scoped active dataset records sorted
    by updated time, creation time and ID. No filesystem inference or dependence
    on query grants/selected dropdown. No active dataset displays **No datasets
    yet**. The user's dataset and document were preserved.
11. **Safe response contract:** additive `config.runtime` on existing ops config
    and health responses: engine, query_provider, query_model, embedding_model,
    latest_dataset. No secrets or provider connection credentials. Frontend uses
    one coherent runtime projection. Managed event preview and stage projections
    are additive reads over existing storage, with unchanged service scopes,
    tenant/grant checks, callback HMAC and ownership validation.
12. **Controlled upload:** `ui-upload-c845d69e262d`, graph disabled, admitted 202,
    real converter/indexer completed, document listed/indexed, byte-identical
    download. Scraper, converter and ingest each produced **2 genuine events**.
    All three stage downloads matched preview text. Report:
    `/tmp/rawki-display-upload.json`. Run the reproducible smoke with:

    ```sh
    RAWKI_UI_SMOKE_GRAPH=false RAWKI_UI_SMOKE_REPORT=/tmp/rawki-display-upload.json node scripts/smoke_local_ui_upload.mjs
    ```

13. **Cleanup:** independent read-only audit PASS: managed revision cleaned,
    source/upload artifacts zero, Qdrant points zero, Neo4j contributions zero,
    active grants zero, workflow completed. Only normal deleted audit/tombstone
    rows remain. The user's `lesen` data was not deleted and workers were not
    recreated for this test. `/tmp/rawki-display-cleanup.json`.
14. **Frontend:** **34 tests passed**, build passed; Node syntax checked by
    build/tests. Headless Chrome verified rendered RAGAnything, model names and
    latest dataset in `/tmp/rawki-display-playground-dom.html`; screenshot
    `/tmp/rawki-display-runtime.png`. Served asset bytes match the build and had
    no actual service-credential, JWT or private-key matches.
15. **Backend:** **459 passed, 118 skipped** across ops, managed documents and URL
    pipeline suites, including new safe runtime metadata and signed managed log
    preview tests. Ruff passed for all five Python files changed in this task.
16. **Readiness:** **26/26 PASS**, `/tmp/rawki-display-readiness.json`.
17. **Deployment:** **43/43 PASS**, `/tmp/rawki-display-deployment.json`.
    Direct backend missing/invalid JWT remains 401; verified service JWT 200.
    Browser remains free from username/password and service credentials.
18. **Diff:** `git diff --check` passed; index empty. Backend/gateway/frontend
    images applied; no issuer, store, crawler or worker auth changes.
19. **Git status:** recorded below, including preserved earlier changes.

Current-task source changes: `frontend/main.js`, dataset CSS,
`HawkiRagPlayground.svelte`, runtime/stage presentation helpers, `pipeline.js`,
ops service metadata, managed repository receipts, URL read projections,
corresponding Python/JS tests, the controlled upload smoke and this report.

DATASET UI, PIPELINE LOGS AND RUNTIME METADATA FIXED — UNCOMMITTED

