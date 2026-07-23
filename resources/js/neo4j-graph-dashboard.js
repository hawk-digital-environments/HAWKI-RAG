import './bootstrap';
import './health-gate.js';
import { mount } from 'svelte';
import GraphExplorerPage from './svelte/apps/GraphExplorerPage.svelte';

let graphVisualizationLoaded = false;

const root = document.querySelector('[data-neo4j-graph-dashboard]');
const configElement = document.getElementById('neo4j-graph-dashboard-config');

function readConfig() {
    if (!configElement?.textContent) {
        return { adminAuthorized: false };
    }

    try {
        return JSON.parse(configElement.textContent);
    } catch (error) {
        console.error('Invalid graph dashboard config.', error);
        return { adminAuthorized: false };
    }
}

if (root) {
    const config = readConfig();

    mount(GraphExplorerPage, {
        target: root,
        props: {
            adminAuthorized: config.adminAuthorized === true,
            onopenTechnicalGraph: config.adminAuthorized === true ? loadGraphVisualization : undefined,
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
