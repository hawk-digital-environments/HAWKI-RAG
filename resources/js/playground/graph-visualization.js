const basePath = import.meta.env.BASE_URL ?? '/';

const neo4jClearBtn = document.getElementById('neo4j-clear-btn');
const neo4jClearNote = document.getElementById('neo4j-clear-note');
const neo4jGraphCanvas = document.getElementById('neo4j-graph-canvas');
const neo4jGraphMeta = document.getElementById('neo4j-graph-meta');
const neo4jGraphEmpty = document.getElementById('neo4j-graph-empty');

let lastGraphSnapshotHash = '';

function csrfToken() {
    return window.playgroundLogs?.csrfToken
        ? window.playgroundLogs.csrfToken()
        : document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function pushActivity(source, message) {
    window.playgroundLogs?.pushActivity?.(source, message);
}

function formatTime(date) {
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function parseTimestamp(value) {
    if (!value && value !== 0) return null;
    if (typeof value === 'number') {
        const ms = value > 1e12 ? value : value * 1000;
        const date = new Date(ms);
        return Number.isNaN(date.getTime()) ? null : date;
    }
    const parsed = Date.parse(String(value).trim());
    if (Number.isNaN(parsed)) return null;
    const date = new Date(parsed);
    return Number.isNaN(date.getTime()) ? null : date;
}

function shortGraphLabel(value, max = 28) {
    const text = String(value || '').trim();
    if (text.length <= max) return text;
    return `${text.slice(0, max - 1)}...`;
}

function graphSnapshotHash(data) {
    if (!data || !Array.isArray(data.nodes) || !Array.isArray(data.links)) return '';
    return `${data.generated_at || ''}|${data.node_count}|${data.relationship_count}|${data.links.map((link) => link.id).join(',')}`;
}

function svgEl(tag, attrs = {}) {
    const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attrs).forEach(([key, value]) => {
        if (value !== undefined && value !== null) el.setAttribute(key, value);
    });
    return el;
}

function estimateTextWidth(text, px = 11) {
    return Math.max(28, String(text || '').length * px * 0.56);
}

function boxesOverlap(a, b, pad = 6) {
    return !(
        a.x + a.width + pad < b.x ||
        b.x + b.width + pad < a.x ||
        a.y + a.height + pad < b.y ||
        b.y + b.height + pad < a.y
    );
}

function placeGraphLabels(nodes, width, height) {
    const boxes = [];
    const ordered = [...nodes].sort((a, b) => (b.degree || 0) - (a.degree || 0));
    ordered.forEach((node) => {
        const label = shortGraphLabel(node.label, 34);
        const textWidth = estimateTextWidth(label, 11);
        const textHeight = 20;
        const gap = (node.radius || 8) + 8;
        const candidates = [
            { x: node.x + gap, y: node.y - textHeight / 2 },
            { x: node.x - gap - textWidth, y: node.y - textHeight / 2 },
            { x: node.x - textWidth / 2, y: node.y + gap },
            { x: node.x - textWidth / 2, y: node.y - gap - textHeight },
            { x: node.x + gap, y: node.y + gap },
            { x: node.x - gap - textWidth, y: node.y + gap },
        ];
        let chosen = candidates.find((candidate) => {
            const box = {
                x: Math.max(8, Math.min(width - textWidth - 8, candidate.x)),
                y: Math.max(8, Math.min(height - textHeight - 8, candidate.y)),
                width: textWidth,
                height: textHeight,
            };
            return !boxes.some((existing) => boxesOverlap(existing, box));
        }) || candidates[0];

        const box = {
            x: Math.max(8, Math.min(width - textWidth - 8, chosen.x)),
            y: Math.max(8, Math.min(height - textHeight - 8, chosen.y)),
            width: textWidth,
            height: textHeight,
        };
        boxes.push(box);
        node.labelText = label;
        node.labelBox = box;
    });
}

function renderNeo4jGraph(data) {
    if (!neo4jGraphCanvas) return;
    const nodes = Array.isArray(data?.nodes) ? data.nodes.slice(0, 90).map((node) => ({ ...node })) : [];
    const links = Array.isArray(data?.links) ? data.links.slice(0, 150).map((link) => ({ ...link })) : [];

    neo4jGraphCanvas.innerHTML = '';
    if (!nodes.length || !links.length) {
        if (neo4jGraphEmpty) neo4jGraphEmpty.style.display = 'block';
        if (neo4jGraphMeta) neo4jGraphMeta.textContent = 'No graph data';
        return;
    }
    if (neo4jGraphEmpty) neo4jGraphEmpty.style.display = 'none';

    const viewportWidth = Math.max(320, neo4jGraphCanvas.clientWidth || 900);
    const viewportHeight = Math.max(360, neo4jGraphCanvas.clientHeight || 520);
    const width = Math.max(1100, viewportWidth * 1.55);
    const height = Math.max(760, viewportHeight * 1.55);
    neo4jGraphCanvas.setAttribute('viewBox', `0 0 ${viewportWidth} ${viewportHeight}`);
    neo4jGraphCanvas.setAttribute('tabindex', '0');

    const nodeById = new Map(nodes.map((node, index) => {
        const angle = (index / Math.max(1, nodes.length)) * Math.PI * 2;
        const ring = 0.22 + ((index % 5) * 0.055);
        node.x = width / 2 + Math.cos(angle) * width * ring;
        node.y = height / 2 + Math.sin(angle) * height * ring;
        node.vx = 0;
        node.vy = 0;
        node.degree = 0;
        return [node.id, node];
    }));
    const usableLinks = links.filter((link) => nodeById.has(link.source) && nodeById.has(link.target));
    usableLinks.forEach((link) => {
        nodeById.get(link.source).degree += 1;
        nodeById.get(link.target).degree += 1;
    });
    nodes.forEach((node) => {
        node.radius = 8 + Math.min(10, Math.sqrt(node.degree) * 3);
    });

    for (let tick = 0; tick < 260; tick += 1) {
        for (let i = 0; i < nodes.length; i += 1) {
            for (let j = i + 1; j < nodes.length; j += 1) {
                const a = nodes[i];
                const b = nodes[j];
                const dx = a.x - b.x || 0.01;
                const dy = a.y - b.y || 0.01;
                const distSq = Math.max(160, dx * dx + dy * dy);
                const force = 6200 / distSq;
                a.vx += dx * force;
                a.vy += dy * force;
                b.vx -= dx * force;
                b.vy -= dy * force;
            }
        }
        usableLinks.forEach((link) => {
            const a = nodeById.get(link.source);
            const b = nodeById.get(link.target);
            const dx = b.x - a.x;
            const dy = b.y - a.y;
            const dist = Math.sqrt(dx * dx + dy * dy) || 1;
            const targetDistance = 190 + Math.min(70, (a.radius + b.radius) * 2);
            const force = (dist - targetDistance) * 0.018;
            const fx = (dx / dist) * force;
            const fy = (dy / dist) * force;
            a.vx += fx;
            a.vy += fy;
            b.vx -= fx;
            b.vy -= fy;
        });
        nodes.forEach((node) => {
            node.vx += (width / 2 - node.x) * 0.004;
            node.vy += (height / 2 - node.y) * 0.004;
            node.vx *= 0.78;
            node.vy *= 0.78;
            node.x = Math.min(width - 80, Math.max(80, node.x + node.vx));
            node.y = Math.min(height - 70, Math.max(70, node.y + node.vy));
        });
    }

    placeGraphLabels(nodes, width, height);

    const defs = svgEl('defs');
    const marker = svgEl('marker', {
        id: 'graph-arrow',
        viewBox: '0 0 10 10',
        refX: '8',
        refY: '5',
        markerWidth: '5',
        markerHeight: '5',
        orient: 'auto-start-reverse',
    });
    marker.appendChild(svgEl('path', { d: 'M 0 0 L 10 5 L 0 10 z', class: 'graph-arrow' }));
    defs.appendChild(marker);
    neo4jGraphCanvas.appendChild(defs);

    const viewport = svgEl('g', { class: 'graph-viewport' });
    const linkLayer = svgEl('g', { class: 'graph-link-layer' });
    const edgeLabelLayer = svgEl('g', { class: 'graph-edge-label-layer' });
    const nodeLayer = svgEl('g', { class: 'graph-node-layer' });
    const labelLayer = svgEl('g', { class: 'graph-label-layer' });
    viewport.append(linkLayer, edgeLabelLayer, nodeLayer, labelLayer);
    neo4jGraphCanvas.appendChild(viewport);

    let transform = {
        x: (viewportWidth - width) / 2,
        y: (viewportHeight - height) / 2,
        scale: Math.min(viewportWidth / width, viewportHeight / height) * 1.05,
    };
    const applyTransform = () => {
        viewport.setAttribute('transform', `translate(${transform.x} ${transform.y}) scale(${transform.scale})`);
    };
    applyTransform();

    const linkElements = [];
    const edgeLabelElements = [];
    const nodeElements = [];
    const nodeLabelElements = [];

    function linkPath(link) {
        const a = nodeById.get(link.source);
        const b = nodeById.get(link.target);
        const dx = b.x - a.x;
        const dy = b.y - a.y;
        const dist = Math.sqrt(dx * dx + dy * dy) || 1;
        const curve = Math.min(70, Math.max(18, dist * 0.13));
        const mx = (a.x + b.x) / 2 - (dy / dist) * curve;
        const my = (a.y + b.y) / 2 + (dx / dist) * curve;
        return `M ${a.x} ${a.y} Q ${mx} ${my} ${b.x} ${b.y}`;
    }

    function updateGraphPositions() {
        linkElements.forEach(({ link, path }) => path.setAttribute('d', linkPath(link)));
        edgeLabelElements.forEach(({ link, group }) => {
            const a = nodeById.get(link.source);
            const b = nodeById.get(link.target);
            group.setAttribute('transform', `translate(${(a.x + b.x) / 2} ${(a.y + b.y) / 2})`);
        });
        nodeElements.forEach(({ node, group }) => group.setAttribute('transform', `translate(${node.x} ${node.y})`));
        nodeLabelElements.forEach(({ node, group }) => {
            group.setAttribute('transform', `translate(${node.labelBox.x} ${node.labelBox.y})`);
        });
    }

    usableLinks.forEach((link) => {
        const path = svgEl('path', {
            class: 'graph-link',
            d: linkPath(link),
            'marker-end': 'url(#graph-arrow)',
        });
        path.appendChild(svgEl('title'));
        path.querySelector('title').textContent = `${nodeById.get(link.source).label} - ${link.label || link.type} -> ${nodeById.get(link.target).label}`;
        linkLayer.appendChild(path);
        linkElements.push({ link, path });

        const edgeLabel = String(link.label || link.type || 'REL');
        const labelWidth = estimateTextWidth(edgeLabel, 9) + 12;
        const group = svgEl('g', { class: 'graph-edge-label-group' });
        group.appendChild(svgEl('rect', {
            x: -labelWidth / 2,
            y: -9,
            width: labelWidth,
            height: 18,
            rx: 5,
            class: 'graph-edge-label-bg',
        }));
        const text = svgEl('text', { class: 'graph-edge-label', 'text-anchor': 'middle', y: 4 });
        text.textContent = edgeLabel;
        group.appendChild(text);
        edgeLabelLayer.appendChild(group);
        edgeLabelElements.push({ link, group });
    });

    nodes.forEach((node) => {
        const group = svgEl('g', { class: 'graph-node-group' });
        const circle = svgEl('circle', { class: 'graph-node', r: node.radius });
        const title = svgEl('title');
        title.textContent = node.label;
        group.append(circle, title);
        nodeLayer.appendChild(group);
        nodeElements.push({ node, group });

        const labelGroup = svgEl('g', { class: 'graph-label-group' });
        labelGroup.appendChild(svgEl('rect', {
            width: node.labelBox.width + 12,
            height: node.labelBox.height,
            rx: 6,
            class: 'graph-label-bg',
        }));
        const label = svgEl('text', { class: 'graph-label', x: 6, y: 14 });
        label.textContent = node.labelText;
        labelGroup.appendChild(label);
        labelLayer.appendChild(labelGroup);
        nodeLabelElements.push({ node, group: labelGroup });
    });

    updateGraphPositions();
    let pinnedNodeId = null;
    let didDragNode = false;

    function setGraphMeta() {
        if (!neo4jGraphMeta) return;
        const generated = parseTimestamp(data.generated_at);
        const time = generated ? formatTime(generated) : 'latest';
        const visibleNote = (data.node_count > nodes.length || data.relationship_count > links.length)
            ? ` · showing ${nodes.length}/${data.node_count} nodes`
            : '';
        neo4jGraphMeta.textContent = `${data.node_count ?? nodes.length} nodes · ${data.relationship_count ?? links.length} relationships · ${time}${visibleNote}`;
    }

    function highlightNode(nodeId, { pinned = false } = {}) {
        const adjacent = new Set([nodeId]);
        const adjacentLinks = new Set();
        usableLinks.forEach((link) => {
            if (link.source === nodeId || link.target === nodeId) {
                adjacent.add(link.source);
                adjacent.add(link.target);
                adjacentLinks.add(link.id);
            }
        });
        nodeElements.forEach(({ node, group }) => {
            group.classList.toggle('is-dim', !adjacent.has(node.id));
            group.classList.toggle('is-highlight', node.id === nodeId);
        });
        nodeLabelElements.forEach(({ node, group }) => {
            group.classList.toggle('is-dim', !adjacent.has(node.id));
            group.classList.toggle('is-highlight', node.id === nodeId);
        });
        linkElements.forEach(({ link, path }) => {
            path.classList.toggle('is-dim', !adjacentLinks.has(link.id));
            path.classList.toggle('is-highlight', adjacentLinks.has(link.id));
        });
        edgeLabelElements.forEach(({ link, group }) => {
            group.classList.toggle('is-visible', adjacentLinks.has(link.id));
            group.classList.toggle('is-pinned', pinned && adjacentLinks.has(link.id));
        });
        if (pinned) {
            neo4jGraphCanvas.classList.add('has-pinned-node');
            if (neo4jGraphMeta) {
                const node = nodeById.get(nodeId);
                neo4jGraphMeta.textContent = `Pinned: ${node?.label || 'node'} · ${adjacentLinks.size} relationships`;
            }
        }
    }

    function clearHighlight({ force = false } = {}) {
        if (pinnedNodeId && !force) return;
        [...nodeElements, ...nodeLabelElements].forEach(({ group }) => {
            group.classList.remove('is-dim', 'is-highlight');
        });
        linkElements.forEach(({ path }) => path.classList.remove('is-dim', 'is-highlight'));
        edgeLabelElements.forEach(({ group }) => group.classList.remove('is-visible', 'is-pinned'));
        neo4jGraphCanvas.classList.remove('has-pinned-node');
    }

    function pinNode(nodeId) {
        pinnedNodeId = pinnedNodeId === nodeId ? null : nodeId;
        if (pinnedNodeId) {
            highlightNode(pinnedNodeId, { pinned: true });
            return;
        }
        clearHighlight({ force: true });
        setGraphMeta();
    }

    let dragNode = null;
    let isPanning = false;
    let lastPoint = null;
    const pointFromEvent = (event) => {
        const rect = neo4jGraphCanvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    };
    const toWorld = (point) => ({
        x: (point.x - transform.x) / transform.scale,
        y: (point.y - transform.y) / transform.scale,
    });

    nodeElements.forEach(({ node, group }) => {
        group.addEventListener('mouseenter', () => {
            if (!pinnedNodeId) highlightNode(node.id);
        });
        group.addEventListener('mouseleave', clearHighlight);
        group.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            dragNode = node;
            didDragNode = false;
            group.setPointerCapture(event.pointerId);
            group.classList.add('is-dragging');
        });
        group.addEventListener('pointermove', (event) => {
            if (!dragNode || dragNode.id !== node.id) return;
            didDragNode = true;
            const world = toWorld(pointFromEvent(event));
            node.x = Math.min(width - 50, Math.max(50, world.x));
            node.y = Math.min(height - 50, Math.max(50, world.y));
            placeGraphLabels(nodes, width, height);
            updateGraphPositions();
        });
        group.addEventListener('pointerup', (event) => {
            dragNode = null;
            group.classList.remove('is-dragging');
            if (!didDragNode) pinNode(node.id);
            try {
                group.releasePointerCapture(event.pointerId);
            } catch (_) {
                // Pointer may already have been released.
            }
        });
    });

    neo4jGraphCanvas.addEventListener('pointerdown', (event) => {
        if (event.target.closest && event.target.closest('.graph-node-group')) return;
        if (pinnedNodeId) {
            pinnedNodeId = null;
            clearHighlight({ force: true });
            setGraphMeta();
        }
        isPanning = true;
        lastPoint = pointFromEvent(event);
        neo4jGraphCanvas.setPointerCapture(event.pointerId);
        neo4jGraphCanvas.classList.add('is-panning');
    }, { once: false });
    neo4jGraphCanvas.addEventListener('pointermove', (event) => {
        if (!isPanning || !lastPoint) return;
        const point = pointFromEvent(event);
        transform.x += point.x - lastPoint.x;
        transform.y += point.y - lastPoint.y;
        lastPoint = point;
        applyTransform();
    });
    neo4jGraphCanvas.addEventListener('pointerup', (event) => {
        isPanning = false;
        lastPoint = null;
        neo4jGraphCanvas.classList.remove('is-panning');
        try {
            neo4jGraphCanvas.releasePointerCapture(event.pointerId);
        } catch (_) {
            // Pointer may already have been released.
        }
    });
    neo4jGraphCanvas.addEventListener('wheel', (event) => {
        event.preventDefault();
        const point = pointFromEvent(event);
        const before = toWorld(point);
        const factor = event.deltaY < 0 ? 1.12 : 0.89;
        transform.scale = Math.min(2.6, Math.max(0.22, transform.scale * factor));
        transform.x = point.x - before.x * transform.scale;
        transform.y = point.y - before.y * transform.scale;
        applyTransform();
    }, { passive: false });

    setGraphMeta();
}

async function pollNeo4jGraphVisualization() {
    if (!neo4jGraphCanvas) return;
    try {
        const response = await fetch(`${basePath}neo4j_graph_visualization.json?ts=${Date.now()}`, {
            headers: { 'Accept': 'application/json' },
        });
        if (!response.ok) {
            neo4jGraphCanvas.innerHTML = '';
            lastGraphSnapshotHash = '';
            if (neo4jGraphEmpty) neo4jGraphEmpty.style.display = 'block';
            if (neo4jGraphMeta) neo4jGraphMeta.textContent = 'No graph snapshot';
            return;
        }
        const data = await response.json();
        const hash = graphSnapshotHash(data);
        if (hash && hash !== lastGraphSnapshotHash) {
            renderNeo4jGraph(data);
            lastGraphSnapshotHash = hash;
            pushActivity('Graph', `Visualization updated · ${data.node_count ?? 0} nodes · ${data.relationship_count ?? 0} relationships`);
        }
    } catch (error) {
        if (neo4jGraphMeta) neo4jGraphMeta.textContent = 'Graph unavailable';
    }
}

if (neo4jClearBtn) {
    neo4jClearBtn.addEventListener('click', async () => {
        const ok = confirm('This will delete ALL Neo4j graph data. This cannot be undone. Continue?');
        if (!ok) return;
        neo4jClearBtn.disabled = true;
        if (neo4jClearNote) neo4jClearNote.textContent = 'Clearing Neo4j graph...';
        try {
            const response = await fetch(basePath + 'rag/neo4j/clear', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                if (neo4jClearNote) neo4jClearNote.textContent = data.message || 'Failed to clear Neo4j graph.';
                pushActivity('Graph', 'Neo4j clear failed');
                return;
            }
            if (neo4jClearNote) neo4jClearNote.textContent = 'Neo4j graph cleared.';
            lastGraphSnapshotHash = '';
            pushActivity('Graph', 'Neo4j graph cleared');
            window.playgroundLogs?.pollRagStats?.();
            pollNeo4jGraphVisualization();
        } catch (error) {
            if (neo4jClearNote) neo4jClearNote.textContent = 'Failed to clear Neo4j graph.';
            pushActivity('Graph', 'Neo4j clear failed');
        } finally {
            neo4jClearBtn.disabled = false;
        }
    });
}

pollNeo4jGraphVisualization();
setInterval(pollNeo4jGraphVisualization, 6000);
