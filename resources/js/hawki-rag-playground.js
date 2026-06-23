import './bootstrap';
import './health-gate.js';
import {mount} from 'svelte';
import HawkiRagPlayground from './svelte/apps/HawkiRagPlayground.svelte';
import {apiUrl} from './playground/urls.js';

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
            uploadDownloadEndpoint: apiUrl('documents/uploads/download'),
        },
    });
}
