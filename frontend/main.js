import '../resources/css/app.css';
import '../resources/css/hawki-rag-theme.css';
import '../resources/css/dashboard-dark-theme.css';
import '../resources/css/datasets-dashboard.css';
import '../resources/css/pipeline-health-dashboard.css';
import {apiFetch} from '../resources/js/api.js';
import {apiUrl} from '../resources/js/playground/urls.js';

const root = document.getElementById('app');
const base = document.querySelector('meta[name="hawki-api-base-path"]').content;
const route = '/' + window.location.pathname.slice(base.length).replace(/^\/+/, '');

function config(id, value) {
    const script = document.createElement('script');
    script.id = id;
    script.type = 'application/json';
    script.textContent = JSON.stringify(value);
    document.body.appendChild(script);
}

async function boot() {
    const pages = {
        '/datasets': ['data-datasets-dashboard', () => import('../resources/js/datasets-dashboard.js')],
        '/documents': ['data-datasets-dashboard', () => import('../resources/js/datasets-dashboard.js')],
        '/admin/datasets': ['data-datasets-dashboard', () => import('../resources/js/datasets-dashboard.js')],
        '/hawki-rag-playground': ['data-hawki-rag-playground', () => import('../resources/js/hawki-rag-playground.js')],
        '/admin/retrieve': ['data-hawki-rag-playground', () => import('../resources/js/hawki-rag-playground.js')],
        '/neo4j-graph-explorer': ['data-neo4j-graph-dashboard', () => import('../resources/js/neo4j-graph-dashboard.js')],
        '/admin/graph': ['data-neo4j-graph-dashboard', () => import('../resources/js/neo4j-graph-dashboard.js')],
        '/pipeline-controller': ['data-pipeline-controller-dashboard', () => import('../resources/js/pipeline-controller.js')],
        '/admin/pipeline': ['data-pipeline-controller-dashboard', () => import('../resources/js/pipeline-controller.js')],
        '/pipeline-health': ['data-pipeline-health-dashboard', () => import('../resources/js/pipeline-health-dashboard.js')],
        '/admin/health-repair': ['data-pipeline-health-dashboard', () => import('../resources/js/pipeline-health-dashboard.js')],
        '/settings': ['data-settings-dashboard', () => import('../resources/js/settings-dashboard.js')],
        '/admin/settings': ['data-settings-dashboard', () => import('../resources/js/settings-dashboard.js')],
    };
    if (['/settings', '/admin/settings', '/pipeline-controller', '/admin/pipeline'].includes(route)) {
        const settings = await apiFetch(apiUrl('ops/config'));
        if (!settings.ok) throw new Error(`Settings unavailable (${settings.status}).`);
        const value = await settings.json();
        config('settings-dashboard-config', value.ui);
        config('pipeline-controller-config', value.uploads);
    }
    const page = pages[route];
    if (page) {
        root.setAttribute(page[0], '');
        await page[1]();
    } else if (['/', '/admin', '/admin/analytics'].includes(route)) {
        config('hawki-rag-experience-config', {
            activeSection: route.endsWith('analytics') ? 'analytics' : 'admin',
            adminRoutes: [
                {key: 'pipeline', label: 'Pipeline', title: 'Pipeline Controller', href: '/admin/pipeline', summary: 'Run ingestion, uploads, conversion, retries, and live stage logs.', service: 'Pipeline', state: 'live'},
                {key: 'datasets', label: 'Datasets', title: 'Dataset Browser', href: '/admin/datasets', summary: 'Browse stored documents, datasets, sources, and recovery context.', service: 'Dataset browser', state: 'ready'},
                {key: 'graph', label: 'Graph', title: 'Graph Explorer', href: '/admin/graph', summary: 'Inspect entities, relations, neighborhoods, and graph paths.', service: 'Graph retrieval', state: 'live'},
                {key: 'retrieve', label: 'Retrieve', title: 'Retrieval Playground', href: '/admin/retrieve', summary: 'Ask questions, compare vector and graph answers, and inspect evidence.', service: 'Retrieval console', state: 'live'},
            ],
        });
        root.setAttribute('data-hawki-rag-experience', '');
        await import('../resources/js/hawki-rag-experience.js');
    } else {
        root.textContent = 'Page not found.';
    }
}

boot().catch((error) => { root.textContent = error.message || 'RAG UI could not start.'; });
