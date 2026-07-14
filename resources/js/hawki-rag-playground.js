import './bootstrap';
import './health-gate.js';
import {mount} from 'svelte';
import HawkiRagPlayground from './svelte/apps/HawkiRagPlayground.svelte';
import {apiUrl} from './playground/urls.js';

const root = document.querySelector('[data-hawki-rag-playground]');
const configElement = document.getElementById('hawki-rag-playground-config');

function readConfig() {
    if (!configElement?.textContent) {
        return { operatorAuthorized: false };
    }

    try {
        return JSON.parse(configElement.textContent);
    } catch (error) {
        console.error('Invalid HAWKI-RAG playground config.', error);
        return { operatorAuthorized: false };
    }
}

if (root) {
    const config = readConfig();

    mount(HawkiRagPlayground, {
        target: root,
        props: {
            queryEndpoint: apiUrl('query'),
            datasetsEndpoint: apiUrl('query/datasets'),
            monitorEndpoint: apiUrl('rag/monitor'),
            statsEndpoint: apiUrl('rag/stats'),
            qdrantCollectionEndpointBase: apiUrl('rag/qdrant/collections'),
            neo4jClearEndpoint: apiUrl('rag/neo4j/clear'),
            uploadDownloadEndpoint: apiUrl('documents/uploads/download'),
            operatorAuthorized: config.operatorAuthorized === true,
        },
    });
}
