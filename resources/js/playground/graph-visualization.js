import cytoscape from 'cytoscape';
import elk from 'cytoscape-elk';
import coseBilkent from 'cytoscape-cose-bilkent';
import { apiUrl } from './urls.js';

cytoscape.use(elk);
cytoscape.use(coseBilkent);

const graphCanvas = document.getElementById('neo4j-graph-canvas');
const graphMeta = document.getElementById('neo4j-graph-meta');
const graphEmpty = document.getElementById('neo4j-graph-empty');
const clearBtn = document.getElementById('neo4j-clear-btn');
const clearNote = document.getElementById('neo4j-clear-note');

const searchInput = document.getElementById('graph-search-input');
const searchResults = document.getElementById('graph-search-results');
const semanticInput = document.getElementById('graph-semantic-input');
const semanticResults = document.getElementById('graph-semantic-results');
const layoutSelect = document.getElementById('graph-layout-select');
const groupingSelect = document.getElementById('graph-grouping-select');
const depthSelect = document.getElementById('graph-depth-select');
const overviewBtn = document.getElementById('graph-overview-btn');
const relayoutBtn = document.getElementById('graph-relayout-btn');
const clearViewBtn = document.getElementById('graph-clear-view-btn');
const snapshotSaveBtn = document.getElementById('graph-snapshot-save-btn');
const snapshotLoad = document.getElementById('graph-snapshot-load');
const snapshotDeleteBtn = document.getElementById('graph-snapshot-delete-btn');
const statusEl = document.getElementById('graph-status');
const detailEl = document.getElementById('graph-detail-panel');

let cy = null;
let selectedNodeId = null;
let searchMatchIds = new Set();
let recentNodeIds = new Set();
let recentEdgeIds = new Set();
let activeSearchQuery = '';
let detailLevel = 'medium';

function csrfToken() {
    return window.playgroundLogs?.csrfToken
        ? window.playgroundLogs.csrfToken()
        : document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function pushActivity(source, message) {
    window.playgroundLogs?.pushActivity?.(source, message);
}

function setStatus(message, tone = 'info') {
    if (!statusEl) return;
    statusEl.textContent = message || '';
    statusEl.dataset.tone = tone;
}

function setMeta(message) {
    if (graphMeta) graphMeta.textContent = message;
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(options.headers || {}),
        },
        ...options,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok === false) {
        throw new Error(data.message || data.error || `Request failed (${response.status})`);
    }
    return data;
}

function shortLabel(value, max = 34) {
    const text = String(value || '').trim();
    return text.length > max ? `${text.slice(0, max - 1)}...` : text;
}

function nodeColor(type) {
    const key = String(type || '').toLowerCase();
    if (key.includes('document')) return '#a78bfa';
    if (key.includes('chunk')) return '#f97316';
    if (key.includes('entity')) return '#38bdf8';
    return '#34d399';
}

function toCyElements(nodes = [], edges = []) {
    const nodeIds = new Set(nodes.map((node) => String(node.id)));
    return [
        ...nodes.map((node) => ({
            group: 'nodes',
            data: {
                ...node,
                id: String(node.id),
                label: shortLabel(node.label || node.id),
                fullLabel: node.label || node.id,
                nodeType: node.type || 'Entity',
                sourceDocumentIds: node.source_document_ids || [],
                weight: Math.max(1, Math.min(12, (node.source_document_ids || []).length || 1)),
                color: nodeColor(node.type),
            },
        })),
        ...edges
            .filter((edge) => nodeIds.has(String(edge.source)) && nodeIds.has(String(edge.target)))
            .map((edge) => ({
                group: 'edges',
                data: {
                    ...edge,
                    id: String(edge.id),
                    source: String(edge.source),
                    target: String(edge.target),
                    label: shortLabel(edge.type || 'REL', 28),
                    fullLabel: edge.type || 'REL',
                    weight: edge.weight || 1,
                },
            })),
    ];
}

function initGraph() {
    if (!graphCanvas || cy) return;

    cy = cytoscape({
        container: graphCanvas,
        elements: [],
        minZoom: 0.08,
        maxZoom: 3.5,
        textureOnViewport: true,
        style: graphStyle(),
    });

    cy.on('tap', 'node', (event) => {
        selectedNodeId = event.target.id();
        updateHighlights();
        renderDetails(event.target);
    });

    cy.on('tap', (event) => {
        if (event.target === cy) {
            selectedNodeId = null;
            updateHighlights();
            renderDetails(null);
        }
    });

    cy.on('dbltap', 'node', (event) => {
        selectedNodeId = event.target.id();
        expandSelected();
    });

    cy.on('mouseover', 'node', (event) => {
        const node = event.target;
        setMeta(`${node.data('fullLabel')} · ${node.connectedEdges().length} relationships`);
    });

    cy.on('zoom', () => {
        const next = cy.zoom() < 0.35 ? 'far' : (cy.zoom() < 0.9 ? 'medium' : 'close');
        if (next !== detailLevel) {
            detailLevel = next;
            cy.batch(() => {
                cy.elements().removeClass('detail-far detail-medium detail-close');
                cy.elements().addClass(`detail-${detailLevel}`);
            });
            setMeta(metaText());
        }
    });
}

function graphStyle() {
    return [
        {
            selector: 'node',
            style: {
                width: 32,
                height: 32,
                'background-color': 'data(color)',
                'border-color': 'rgba(248, 250, 252, 0.86)',
                'border-width': 2,
                label: 'data(label)',
                color: '#e5eefb',
                'font-size': 10,
                'font-weight': 700,
                'text-outline-width': 3,
                'text-outline-color': '#07111f',
                'text-valign': 'bottom',
                'text-margin-y': 7,
                'overlay-opacity': 0,
            },
        },
        {
            selector: 'node[weight]',
            style: {
                width: 'mapData(weight, 1, 12, 28, 62)',
                height: 'mapData(weight, 1, 12, 28, 62)',
            },
        },
        {
            selector: 'edge',
            style: {
                width: 'mapData(weight, 1, 8, 1.2, 4)',
                'line-color': 'rgba(147, 197, 253, 0.62)',
                'target-arrow-color': 'rgba(147, 197, 253, 0.62)',
                'target-arrow-shape': 'triangle',
                'curve-style': 'bezier',
                label: 'data(label)',
                color: '#bfdbfe',
                'font-size': 8,
                'text-outline-width': 3,
                'text-outline-color': '#07111f',
                'text-rotation': 'autorotate',
            },
        },
        { selector: 'edge.detail-far', style: { label: '', width: 1, 'target-arrow-shape': 'none' } },
        { selector: 'node.detail-far', style: { label: 'data(nodeType)', 'font-size': 8 } },
        { selector: 'edge.detail-medium', style: { label: 'data(label)' } },
        { selector: 'node.detail-close', style: { label: 'data(fullLabel)', 'font-size': 11 } },
        { selector: 'edge.detail-close', style: { label: 'data(fullLabel)', 'font-size': 9 } },
        {
            selector: '.selected',
            style: {
                'border-color': '#facc15',
                'border-width': 5,
                'background-color': '#f59e0b',
                'z-index': 20,
            },
        },
        {
            selector: '.neighbor',
            style: {
                'border-color': '#67e8f9',
                'border-width': 4,
                'z-index': 15,
            },
        },
        {
            selector: '.search-match',
            style: {
                'border-color': '#22c55e',
                'border-width': 5,
                'background-color': '#22c55e',
            },
        },
        {
            selector: '.recent',
            style: {
                'border-color': '#fef3c7',
                'border-width': 4,
            },
        },
        {
            selector: 'edge.recent',
            style: {
                'line-color': '#22c55e',
                'target-arrow-color': '#22c55e',
                width: 4,
            },
        },
        {
            selector: '.dimmed',
            style: {
                opacity: 0.18,
            },
        },
        {
            selector: ':parent',
            style: {
                'background-opacity': 0.08,
                'background-color': '#38bdf8',
                'border-color': 'rgba(125, 211, 252, 0.38)',
                'border-style': 'dashed',
                'border-width': 1,
                label: 'data(label)',
                'font-size': 11,
                color: '#bae6fd',
                'text-valign': 'top',
                'text-halign': 'center',
            },
        },
    ];
}

function layoutOptions(name = layoutSelect?.value || 'elk') {
    if (name === 'cose-bilkent') {
        return {
            name: 'cose-bilkent',
            animate: 'end',
            animationDuration: 450,
            fit: true,
            padding: 50,
            randomize: false,
            nodeRepulsion: 6500,
            idealEdgeLength: 130,
            edgeElasticity: 0.35,
            nestingFactor: 0.2,
        };
    }

    return {
        name: 'elk',
        fit: true,
        padding: 45,
        animate: true,
        animationDuration: 350,
        nodeDimensionsIncludeLabels: true,
        elk: {
            algorithm: 'layered',
            'elk.direction': 'RIGHT',
            'elk.spacing.nodeNode': '55',
            'elk.layered.spacing.nodeNodeBetweenLayers': '80',
        },
    };
}

function runLayout() {
    if (!cy || cy.nodes(':childless').empty()) return;
    setStatus('Laying out graph...');
    cy.layout({ ...layoutOptions(), stop: () => setStatus('') }).run();
}

function mergeGraph(payload, { markRecent = false, markSearch = false, runLayoutAfter = true } = {}) {
    initGraph();
    const nodes = payload.nodes || [];
    const edges = payload.edges || [];
    const existing = new Set(cy.elements().map((element) => element.id()));
    const elements = toCyElements(nodes, edges).filter((element) => !existing.has(element.data.id));

    cy.batch(() => {
        cy.add(elements);
        if (markRecent) {
            recentNodeIds = new Set(nodes.map((node) => String(node.id)));
            recentEdgeIds = new Set(edges.map((edge) => String(edge.id)));
        }
        if (markSearch) {
            searchMatchIds = new Set(nodes.map((node) => String(node.id)));
        }
        applyGrouping();
        updateHighlights();
        cy.elements().addClass(`detail-${detailLevel}`);
    });

    if (graphEmpty) graphEmpty.style.display = cy.elements().length ? 'none' : 'block';
    setMeta(metaText());
    if (runLayoutAfter) runLayout();
}

function applyGrouping() {
    if (!cy) return;
    const mode = groupingSelect?.value || 'none';

    cy.batch(() => {
        cy.nodes(':childless').move({ parent: null });
        cy.nodes(':parent').remove();
        if (mode === 'none') return;

        const groups = new Map();
        cy.nodes(':childless').forEach((node) => {
            const ids = node.data('sourceDocumentIds') || [];
            const key = mode === 'source'
                ? (ids[0] ? `Source ${ids[0]}` : 'Source unknown')
                : mode === 'community'
                    ? `Community ${communityBucket(node.id())}`
                    : node.data('nodeType') || 'Entity';
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(node);
        });

        groups.forEach((nodes, key) => {
            if (nodes.length < 2) return;
            const parentId = `group:${mode}:${key}`;
            if (!cy.getElementById(parentId).length) {
                cy.add({ group: 'nodes', data: { id: parentId, label: key, nodeType: 'Group', color: '#0f172a', weight: 1 } });
            }
            nodes.forEach((node) => node.move({ parent: parentId }));
        });
    });
}

function communityBucket(id) {
    let hash = 0;
    for (const char of String(id)) hash = ((hash << 5) - hash) + char.charCodeAt(0);
    return Math.abs(hash % 6) + 1;
}

function updateHighlights() {
    if (!cy) return;
    cy.batch(() => {
        cy.elements().removeClass('selected neighbor search-match recent dimmed');
        searchMatchIds.forEach((id) => cy.getElementById(id).addClass('search-match'));
        recentNodeIds.forEach((id) => cy.getElementById(id).addClass('recent'));
        recentEdgeIds.forEach((id) => cy.getElementById(id).addClass('recent'));

        if (selectedNodeId) {
            const selected = cy.getElementById(selectedNodeId);
            const neighborhood = selected.closedNeighborhood();
            selected.addClass('selected');
            neighborhood.addClass('neighbor');
            cy.elements().not(neighborhood).addClass('dimmed');
        }
    });
}

function metaText() {
    if (!cy) return 'No graph loaded';
    return `${cy.nodes(':childless').length} nodes · ${cy.edges().length} relationships · ${detailLevel} zoom`;
}

function renderDetails(node) {
    if (!detailEl) return;
    if (!node) {
        detailEl.innerHTML = '<p class="muted">Select a node to inspect entity metadata and expand neighbors.</p>';
        return;
    }

    const props = node.data('properties') || {};
    const docs = node.data('sourceDocumentIds') || [];
    detailEl.innerHTML = `
        <div class="graph-detail-title">${escapeHtml(node.data('fullLabel'))}</div>
        <div class="graph-detail-subtitle">${escapeHtml(node.data('nodeType'))} · ${node.connectedEdges().length} relationships</div>
        <button type="button" id="graph-expand-selected-btn">Expand neighbors</button>
        <dl class="graph-detail-list">
            ${Object.entries(props).slice(0, 10).map(([key, value]) => `
                <dt>${escapeHtml(key)}</dt><dd>${escapeHtml(formatValue(value))}</dd>
            `).join('')}
            <dt>Source documents</dt><dd>${docs.length ? docs.map(escapeHtml).join(', ') : 'None'}</dd>
        </dl>
    `;
    document.getElementById('graph-expand-selected-btn')?.addEventListener('click', expandSelected);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[char]));
}

function formatValue(value) {
    if (Array.isArray(value)) return value.join(', ');
    if (value && typeof value === 'object') return JSON.stringify(value);
    return value ?? '';
}

async function loadOverview() {
    setStatus('Loading graph overview...');
    try {
        const data = await requestJson(apiUrl('rag/neo4j/graph/overview?limit=80'));
        cy?.elements().remove();
        recentNodeIds = new Set();
        recentEdgeIds = new Set();
        searchMatchIds = new Set();
        selectedNodeId = null;
        mergeGraph(data);
        setStatus('');
        pushActivity('Graph', `Cytoscape overview loaded · ${data.nodes.length} nodes · ${data.edges.length} edges`);
    } catch (error) {
        setStatus(error.message, 'error');
    }
}

async function searchEntities(query) {
    activeSearchQuery = query;
    if (!query.trim()) {
        searchResults.innerHTML = '';
        return;
    }
    searchResults.innerHTML = '<div class="graph-result-muted">Searching...</div>';
    try {
        const data = await requestJson(apiUrl(`rag/neo4j/graph/search?q=${encodeURIComponent(query)}&limit=12`));
        renderSearchResults(searchResults, data.results || [], data.warnings || []);
    } catch (error) {
        searchResults.innerHTML = `<div class="graph-result-error">${escapeHtml(error.message)}</div>`;
    }
}

async function semanticSearch(query) {
    activeSearchQuery = query;
    if (!query.trim()) return;
    semanticResults.innerHTML = '<div class="graph-result-muted">Searching semantically...</div>';
    try {
        const data = await requestJson(apiUrl(`rag/neo4j/graph/semantic-search?q=${encodeURIComponent(query)}&limit=8`));
        renderSearchResults(semanticResults, data.results || [], data.warnings || []);
    } catch (error) {
        semanticResults.innerHTML = `<div class="graph-result-error">${escapeHtml(error.message)}</div>`;
    }
}

function renderSearchResults(container, results, warnings = []) {
    if (!container) return;
    const warningHtml = warnings.map((warning) => `<div class="graph-result-warning">${escapeHtml(warning)}</div>`).join('');
    if (!results.length) {
        container.innerHTML = `${warningHtml}<div class="graph-result-muted">No matching entities.</div>`;
        return;
    }

    container.innerHTML = warningHtml + results.map((node) => `
        <button type="button" class="graph-result" data-node-id="${escapeHtml(node.id)}">
            <span>${escapeHtml(node.label)}</span>
            <small>${escapeHtml(node.type)}${node.score ? ` · ${Number(node.score).toFixed(2)}` : ''}</small>
        </button>
    `).join('');

    container.querySelectorAll('.graph-result').forEach((button) => {
        button.addEventListener('click', () => loadNode(button.dataset.nodeId));
    });
}

async function loadNode(nodeId) {
    if (!nodeId) return;
    setStatus('Loading entity neighborhood...');
    try {
        const data = await requestJson(apiUrl(`rag/neo4j/graph/node?node_id=${encodeURIComponent(nodeId)}&limit=100`));
        searchMatchIds = new Set([nodeId]);
        recentNodeIds = new Set(data.nodes.map((node) => String(node.id)));
        recentEdgeIds = new Set(data.edges.map((edge) => String(edge.id)));
        cy?.elements().remove();
        mergeGraph(data, { markSearch: true, markRecent: true });
        selectedNodeId = nodeId;
        updateHighlights();
        renderDetails(cy.getElementById(nodeId));
        setStatus('');
    } catch (error) {
        setStatus(error.message, 'error');
    }
}

async function expandSelected() {
    if (!selectedNodeId) {
        setStatus('Select a node first.', 'warn');
        return;
    }
    setStatus('Expanding neighbors...');
    try {
        const data = await requestJson(apiUrl('rag/neo4j/graph/expand'), {
            method: 'POST',
            body: JSON.stringify({
                node_id: selectedNodeId,
                depth: Number(depthSelect?.value || 1),
                limit: 160,
            }),
        });
        mergeGraph(data, { markRecent: true });
        selectedNodeId = data.expanded_node_id || selectedNodeId;
        updateHighlights();
        renderDetails(cy.getElementById(selectedNodeId));
        setStatus('');
    } catch (error) {
        setStatus(error.message, 'error');
    }
}

function sceneSnapshot() {
    return {
        nodes: cy.nodes(':childless').map((node) => ({ data: node.data(), position: node.position() })),
        edges: cy.edges().map((edge) => ({ data: edge.data() })),
        zoom: cy.zoom(),
        pan: cy.pan(),
        active_filters: { grouping: groupingSelect?.value || 'none', layout: layoutSelect?.value || 'elk' },
        selected_node: selectedNodeId,
        search_query: activeSearchQuery,
        detail_level: detailLevel,
    };
}

async function saveSnapshot() {
    if (!cy || cy.elements().empty()) {
        setStatus('Nothing to save yet.', 'warn');
        return;
    }
    setStatus('Saving snapshot...');
    try {
        await requestJson(apiUrl('rag/neo4j/graph/snapshots'), {
            method: 'POST',
            body: JSON.stringify({ name: activeSearchQuery ? `Search: ${activeSearchQuery}` : null, scene: sceneSnapshot() }),
        });
        await loadSnapshotList();
        setStatus('Snapshot saved.');
    } catch (error) {
        setStatus(error.message, 'error');
    }
}

async function loadSnapshotList() {
    if (!snapshotLoad) return;
    const data = await requestJson(apiUrl('rag/neo4j/graph/snapshots'));
    snapshotLoad.innerHTML = '<option value="">Load snapshot...</option>' + (data.snapshots || []).map((snapshot) => (
        `<option value="${escapeHtml(snapshot.id)}">${escapeHtml(snapshot.name)}</option>`
    )).join('');
}

async function loadSnapshot(id) {
    if (!id) return;
    setStatus('Loading snapshot...');
    try {
        const data = await requestJson(apiUrl(`rag/neo4j/graph/snapshots/${encodeURIComponent(id)}`));
        const scene = data.snapshot?.scene || {};
        cy.elements().remove();
        cy.add([...(scene.nodes || []).map((node) => ({ group: 'nodes', data: node.data, position: node.position })), ...(scene.edges || []).map((edge) => ({ group: 'edges', data: edge.data }))]);
        selectedNodeId = scene.selected_node || null;
        detailLevel = scene.detail_level || 'medium';
        if (groupingSelect && scene.active_filters?.grouping) groupingSelect.value = scene.active_filters.grouping;
        if (layoutSelect && scene.active_filters?.layout) layoutSelect.value = scene.active_filters.layout;
        activeSearchQuery = scene.search_query || '';
        applyGrouping();
        updateHighlights();
        cy.zoom(scene.zoom || 1);
        cy.pan(scene.pan || { x: 0, y: 0 });
        renderDetails(selectedNodeId ? cy.getElementById(selectedNodeId) : null);
        setMeta(metaText());
        setStatus('');
    } catch (error) {
        setStatus(error.message, 'error');
    }
}

function debounce(callback, wait = 300) {
    let timeout = null;
    return (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => callback(...args), wait);
    };
}

if (graphCanvas) {
    initGraph();
    loadSnapshotList().catch(() => {});
    loadOverview();

    searchInput?.addEventListener('input', debounce((event) => searchEntities(event.target.value), 350));
    semanticInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') semanticSearch(event.target.value);
    });
    overviewBtn?.addEventListener('click', loadOverview);
    relayoutBtn?.addEventListener('click', runLayout);
    groupingSelect?.addEventListener('change', () => {
        applyGrouping();
        runLayout();
    });
    layoutSelect?.addEventListener('change', runLayout);
    clearViewBtn?.addEventListener('click', () => {
        cy.elements().remove();
        selectedNodeId = null;
        renderDetails(null);
        setMeta('Graph view cleared');
    });
    snapshotSaveBtn?.addEventListener('click', saveSnapshot);
    snapshotLoad?.addEventListener('change', (event) => loadSnapshot(event.target.value));
    snapshotDeleteBtn?.addEventListener('click', async () => {
        if (!snapshotLoad?.value) return;
        await requestJson(apiUrl(`rag/neo4j/graph/snapshots/${encodeURIComponent(snapshotLoad.value)}`), { method: 'DELETE' });
        await loadSnapshotList();
        setStatus('Snapshot deleted.');
    });
}

if (clearBtn) {
    clearBtn.addEventListener('click', async () => {
        const ok = confirm('This will delete ALL Neo4j graph data. This cannot be undone. Continue?');
        if (!ok) return;
        clearBtn.disabled = true;
        if (clearNote) clearNote.textContent = 'Clearing Neo4j graph...';
        try {
            const data = await requestJson(apiUrl('rag/neo4j/clear'), { method: 'POST', body: JSON.stringify({}) });
            if (clearNote) clearNote.textContent = data.ok ? 'Neo4j graph cleared.' : 'Failed to clear Neo4j graph.';
            cy?.elements().remove();
            window.playgroundLogs?.pollRagStats?.();
        } catch (error) {
            if (clearNote) clearNote.textContent = error.message;
        } finally {
            clearBtn.disabled = false;
        }
    });
}
