import './bootstrap';
import './health-gate.js';
import { mount } from 'svelte';
import PipelineControllerPage from './svelte/apps/PipelineControllerPage.svelte';
import { apiUrl } from './playground/urls.js';

const root = document.querySelector('[data-pipeline-controller-dashboard]');
const configElement = document.getElementById('pipeline-controller-config');

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function readConfig() {
    if (!configElement?.textContent) {
        return {
            nativeExtensions: [],
            customExtensions: [],
            customConverter: {},
            adminAuthorized: false,
        };
    }

    try {
        return JSON.parse(configElement.textContent);
    } catch (error) {
        console.error('Invalid pipeline controller config.', error);
        return {
            nativeExtensions: [],
            customExtensions: [],
            customConverter: {},
            adminAuthorized: false,
        };
    }
}

function extensionList(value) {
    return Array.isArray(value)
        ? value.map((extension) => String(extension || '').trim()).filter(Boolean)
        : [];
}

function bootPipelineRuntime() {
    import('./playground/pipeline.js').catch((error) => {
        console.error('Failed to load pipeline controller runtime.', error);
    });
}

if (root) {
    const config = readConfig();

    mount(PipelineControllerPage, {
        target: root,
        props: {
            uploadEndpoint: apiUrl('pipeline/controller/files'),
            csrfToken: csrfToken(),
            nativeExtensions: extensionList(config.nativeExtensions),
            customExtensions: extensionList(config.customExtensions),
            customConverter: config.customConverter || {},
            adminAuthorized: config.adminAuthorized === true,
            onready: config.adminAuthorized === true ? bootPipelineRuntime : undefined,
        },
    });
}
