import './bootstrap';
import './health-gate.js';
import { mount } from 'svelte';
import SettingsPage from './svelte/apps/SettingsPage.svelte';
import { apiUrl } from './playground/urls.js';

const root = document.querySelector('[data-settings-dashboard]');
const configElement = document.getElementById('settings-dashboard-config');

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function readConfig() {
    if (!configElement?.textContent) {
        return { operatorAuthorized: false };
    }

    try {
        return JSON.parse(configElement.textContent);
    } catch (error) {
        console.error('Invalid settings dashboard config.', error);
        return { operatorAuthorized: false };
    }
}

if (root) {
    const config = readConfig();

    mount(SettingsPage, {
        target: root,
        props: {
            endpoint: apiUrl('settings/config'),
            csrfToken: csrfToken(),
            initialConfig: config,
            operatorAuthorized: config.operatorAuthorized === true,
        },
    });
}
