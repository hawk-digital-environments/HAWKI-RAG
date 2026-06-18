import './bootstrap';
import './health-gate.js';
import {mount} from 'svelte';
import HawkiRagPlayground from './svelte/apps/HawkiRagPlayground.svelte';
import {apiUrl, pageUrl} from './playground/urls.js';

const root = document.querySelector('[data-hawki-rag-playground]');

if (root) {
    mount(HawkiRagPlayground, {
        target: root,
        props: {
            queryEndpoint: apiUrl('query'),
            monitorEndpoint: apiUrl('rag/monitor'),
            statsEndpoint: apiUrl('rag/stats'),
            qdrantCollectionEndpointBase: apiUrl('rag/qdrant/collections'),
            neo4jClearEndpoint: apiUrl('rag/neo4j/clear'),
            navItems: [
                {key: 'health', label: 'Health', href: pageUrl('/pipeline-health')},
                {key: 'datasets', label: 'Data Browser', href: pageUrl('/datasets')},
                {key: 'controller', label: 'Controller', href: pageUrl('/pipeline-controller')},
                {key: 'graph', label: 'Neo4j Graph', href: pageUrl('/neo4j-graph-explorer')},
                {key: 'playground', label: 'Playground', href: pageUrl('/hawki-rag-playground')},
            ],
        },
    });
}
