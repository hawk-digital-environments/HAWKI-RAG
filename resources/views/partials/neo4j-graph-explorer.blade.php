<section class="graph-visualization-section">
    <div class="graph-visualization-header">
        <div>
            <h2>Graph Workspace</h2>
            <p>Search, expand, group, and save interactive graph scenes.</p>
        </div>
        <div id="neo4j-graph-meta" class="badge">Loading graph...</div>
    </div>

    <div class="graph-explorer-shell">
        <aside class="graph-toolbar" aria-label="Graph controls">
            <div class="graph-control-group">
                <label for="graph-search-input">Entity search</label>
                <input id="graph-search-input" type="search" placeholder="Search entities..." autocomplete="off" />
                <div id="graph-search-results" class="graph-results"></div>
            </div>

            <div class="graph-control-group">
                <label for="graph-semantic-input">Semantic search</label>
                <input id="graph-semantic-input" type="search" placeholder="Ask for a concept..." autocomplete="off" />
                <div id="graph-semantic-results" class="graph-results"></div>
            </div>

            <div class="graph-control-grid">
                <div>
                    <label for="graph-layout-select">Layout</label>
                    <select id="graph-layout-select">
                        <option value="elk" selected>ELK layered</option>
                        <option value="cose-bilkent">CoSE Bilkent</option>
                    </select>
                </div>
                <div>
                    <label for="graph-grouping-select">Grouping</label>
                    <select id="graph-grouping-select">
                        <option value="none" selected>None</option>
                        <option value="type">Entity type</option>
                        <option value="source">Source document</option>
                        <option value="community">Community</option>
                    </select>
                </div>
                <div>
                    <label for="graph-depth-select">Depth</label>
                    <select id="graph-depth-select">
                        <option value="1" selected>1 hop</option>
                        <option value="2">2 hops</option>
                        <option value="3">3 hops</option>
                    </select>
                </div>
            </div>

            <div class="graph-actions">
                <button type="button" id="graph-overview-btn">Overview</button>
                <button type="button" id="graph-relayout-btn">Layout</button>
                <button type="button" id="graph-clear-view-btn">Clear view</button>
            </div>

            <div class="graph-control-group">
                <label for="graph-snapshot-load">Snapshots</label>
                <div class="graph-snapshot-row">
                    <select id="graph-snapshot-load">
                        <option value="">Load snapshot...</option>
                    </select>
                    <button type="button" id="graph-snapshot-save-btn">Save</button>
                    <button type="button" id="graph-snapshot-delete-btn">Delete</button>
                </div>
            </div>

            <div id="graph-status" class="graph-status" role="status"></div>
        </aside>

        <main class="graph-stage">
            <div id="neo4j-graph-empty" class="graph-empty">Search for an entity or load the limited overview.</div>
            <div id="neo4j-graph-canvas" class="graph-canvas" role="img" aria-label="Neo4j graph visualization"></div>
        </main>

        <aside class="graph-detail" aria-label="Selected entity details">
            <h3>Entity Details</h3>
            <div id="graph-detail-panel">
                <p class="muted">Select a node to inspect entity metadata and expand neighbors.</p>
            </div>
        </aside>
    </div>
</section>
