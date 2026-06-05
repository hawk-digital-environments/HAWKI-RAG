@php
    $converterExtensions = collect(config('file_converter.supported_extensions', ['pdf', 'doc', 'docx']))
        ->map(fn ($extension) => ltrim(strtolower(trim((string) $extension)), '.'))
        ->filter()
        ->unique()
        ->values();
    $converterAccept = $converterExtensions->map(fn ($extension) => '.' . $extension)->implode(',');
@endphp

<section class="controller-file-section" aria-labelledby="pipeline-file-input-title">
    <div class="pipeline-panel-head">
        <div>
            <span class="pipeline-kicker">File input</span>
            <h2 id="pipeline-file-input-title">Convert and Ingest Document</h2>
        </div>
    </div>

    <form id="pipeline-file-form" class="controller-file-form" enctype="multipart/form-data">
        <div class="controller-file-grid">
            <div>
                <label for="pipeline-file-dataset">Dataset ID</label>
                <input id="pipeline-file-dataset" name="dataset_id" type="text" value="controller-uploads" autocomplete="off" />
            </div>
            <div>
                <label for="pipeline-file-input">Document</label>
                <input id="pipeline-file-input" name="file" type="file" accept="{{ $converterAccept }}" data-supported-extensions="{{ $converterExtensions->implode(',') }}" />
            </div>
            <label class="controller-toggle" for="pipeline-file-graph">
                <input id="pipeline-file-graph" name="graph" type="checkbox" value="true" checked />
                <span>Neo4j graph</span>
            </label>
        </div>
        <div class="controller-file-actions">
            <button type="submit" id="pipeline-file-submit">Convert and Ingest File</button>
            <span id="pipeline-file-note" class="pipeline-task-note" aria-live="polite"></span>
        </div>
    </form>
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
        </main>
    </div>
</section>
