@php
    $nativeExtensions = collect(config('file_converter.raganything_supported_extensions', []))
        ->map(fn ($extension) => ltrim(strtolower(trim((string) $extension)), '.'))
        ->filter()
        ->unique()
        ->values();
    $nativeAccept = $nativeExtensions->map(fn ($extension) => '.' . $extension)->implode(',');
    $customConverterExtensions = collect(config('file_converter.supported_extensions', []))
        ->map(fn ($extension) => ltrim(strtolower(trim((string) $extension)), '.'))
        ->filter()
        ->unique()
        ->values();
@endphp

<section class="controller-file-section" aria-labelledby="pipeline-file-input-title">
    <div
        id="pipeline-upload-module"
        data-native-extensions="{{ $nativeExtensions->implode(',') }}"
        data-native-accept="{{ $nativeAccept }}"
        data-custom-extensions="{{ $customConverterExtensions->implode(',') }}"
    ></div>
</section>

<section class="pipeline-operations-section">
    <div class="pipeline-hero">
        <div class="pipeline-heading">
            <span class="pipeline-kicker">Scraper Pipeline</span>
            <h2>Pipeline Control</h2>
        </div>
        <div class="pipeline-current-wrap">
            <span id="pipeline-current" class="badge">No pipeline selected.</span>
            <span id="pipeline-job-id" class="pipeline-job-id">Job ID: none</span>
        </div>
    </div>
    <div class="pipeline-workspace">
        <aside class="pipeline-task-panel">
            <div class="pipeline-panel-head">
                <h3>Scraper Tasks</h3>
                <button type="button" id="pipeline-task-refresh-btn" class="pipeline-secondary-btn">Refresh</button>
            </div>
            <label for="pipeline-task-select">Available task</label>
            <select id="pipeline-task-select">
                <option value="">Loading scraper tasks...</option>
            </select>
            <div class="pipeline-task-summary">
                <span><strong id="pipeline-task-count">0</strong> tasks</span>
                <span id="pipeline-task-source">Source: none</span>
            </div>
            <div id="pipeline-task-detail" class="pipeline-task-detail" hidden></div>
            <div id="pipeline-task-note" class="pipeline-task-note"></div>
            <button type="button" id="pipeline-task-start-btn">Start Pipeline Task</button>

            <div class="pipeline-run-list-block">
                <div class="pipeline-panel-head">
                    <h3>Pipeline Tasks</h3>
                    <button type="button" id="pipeline-run-refresh-btn" class="pipeline-secondary-btn">Refresh</button>
                </div>
                <div id="pipeline-run-list" class="pipeline-run-list">
                    <button type="button" disabled>Loading pipeline tasks...</button>
                </div>
            </div>
        </aside>

        <main class="pipeline-stage-panel">
            <div class="pipeline-stage-header">
                <div>
                    <h3>Stage State</h3>
                    <p id="pipeline-dataset-path">Dataset path: none</p>
                </div>
                <div id="pipeline-updated-at" class="pipeline-updated-at"></div>
            </div>
            <div id="pipeline-task-run" class="pipeline-task-run" hidden></div>
            <div id="pipeline-stages" class="pipeline-stages pipeline-stages-expanded"></div>
            <section class="pipeline-stage-log-panel" aria-labelledby="pipeline-stage-log-title">
                <div class="pipeline-stage-log-head">
                    <div>
                        <h4 id="pipeline-stage-log-title">Stage logs</h4>
                        <p id="pipeline-stage-log-status">Select Scrape, Convert, or Ingest logs from a stage card.</p>
                    </div>
                </div>
                <pre id="pipeline-stage-log-viewer" class="pipeline-stage-log-viewer">No stage log selected.</pre>
            </section>
        </main>
    </div>
</section>
