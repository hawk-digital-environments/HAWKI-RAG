import './bootstrap';
import './health-gate.js';
import './playground/pipeline.js';
import { mount } from 'svelte';
import PipelineUploadModule from './svelte/apps/PipelineUploadModule.svelte';
import { apiUrl } from './playground/urls.js';

const refreshButton = document.getElementById('pipeline-controller-refresh');
const uploadModule = document.getElementById('pipeline-upload-module');

refreshButton?.addEventListener('click', () => {
    window.location.reload();
});

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function parseExtensions(value) {
    return String(value || '')
        .split(',')
        .map((extension) => extension.trim().toLowerCase().replace(/^\.+/, ''))
        .filter(Boolean);
}

if (uploadModule) {
    mount(PipelineUploadModule, {
        target: uploadModule,
        props: {
            endpoint: apiUrl('pipeline/controller/files'),
            csrfToken: csrfToken(),
            nativeExtensions: parseExtensions(uploadModule.dataset.nativeExtensions),
            customExtensions: parseExtensions(uploadModule.dataset.customExtensions),
            onqueued: (taskId) => {
                window.hawkiPipelineController?.selectTask?.(taskId);
                window.hawkiPipelineController?.refreshRuns?.();
            },
        },
    });
}
