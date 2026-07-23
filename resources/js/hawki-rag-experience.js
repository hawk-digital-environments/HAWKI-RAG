import './bootstrap';
import './health-gate.js';
import { mount } from 'svelte';
import HawkiRagExperience from './svelte/apps/HawkiRagExperience.svelte';
import { pageUrl } from './playground/urls.js';

const root = document.querySelector('[data-hawki-rag-experience]');
const configElement = document.getElementById('hawki-rag-experience-config');

function parseConfig() {
    if (!configElement?.textContent) return {};

    try {
        return JSON.parse(configElement.textContent);
    } catch (error) {
        console.error('Failed to parse HAWKI-RAG experience config.', error);
        return {};
    }
}

function withPageUrls(items = []) {
    return items.map((item) => ({
        ...item,
        href: pageUrl(item.href || '/admin'),
    }));
}

if (root) {
    const config = parseConfig();

    mount(HawkiRagExperience, {
        target: root,
        props: {
            adminRoutes: withPageUrls(config.adminRoutes || []),
        },
    });
}
