import './health-gate.js';
import { mount } from 'svelte';
import GraphExplorerPage from './svelte/apps/GraphExplorerPage.svelte';

let graphVisualizationLoaded = false;

const root = document.querySelector('[data-neo4j-graph-dashboard]');

if (root) {
    mount(GraphExplorerPage, {
        target: root,
        props: {
            onopenTechnicalGraph: loadGraphVisualization,
        },
    });
}

function loadGraphVisualization() {
    if (graphVisualizationLoaded || !document.getElementById('neo4j-graph-canvas')) return;
    graphVisualizationLoaded = true;
    import('./playground/graph-visualization.js').catch((error) => {
        graphVisualizationLoaded = false;
        console.error('Failed to load graph visualization.', error);
    });
}

if (!root) {
    loadGraphVisualization();
}
