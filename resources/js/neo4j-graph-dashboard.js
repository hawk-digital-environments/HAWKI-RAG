import './bootstrap';
import './health-gate.js';
import { mount } from 'svelte';
import GraphExplorerPage from './svelte/apps/GraphExplorerPage.svelte';
import { apiUrl, pageUrl } from './playground/urls.js';

let graphVisualizationLoaded = false;

const root = document.querySelector('[data-neo4j-graph-dashboard]');
if (root) {
    mount(GraphExplorerPage, {
        target: root,
        props: {
            overviewEndpoint: apiUrl('rag/neo4j/graph/overview?limit=80'),
            searchEndpoint: apiUrl('rag/neo4j/graph/search'),
            semanticSearchEndpoint: apiUrl('rag/neo4j/graph/semantic-search'),
            nodeEndpoint: apiUrl('rag/neo4j/graph/node'),
            navItems: [
                { key: 'health', label: 'Health', href: pageUrl('/pipeline-health') },
                { key: 'datasets', label: 'Data Browser', href: pageUrl('/datasets') },
                { key: 'controller', label: 'Controller', href: pageUrl('/pipeline-controller') },
                { key: 'graph', label: 'Neo4j Graph', href: pageUrl('/neo4j-graph-explorer') },
                { key: 'playground', label: 'Playground', href: pageUrl('/hawki-rag-playground') },
            ],
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
