import {apiFetch} from '../api.js';
import {apiUrl} from './urls.js';

async function post(path, body, {fetcher = apiFetch, url = apiUrl} = {}) {
    const response = await fetcher(url(path), {
        method: 'POST',
        headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success !== true) {
        throw new Error(data.message || `Dataset preparation failed (${response.status}).`);
    }
    return data;
}

export async function prepareUploadDataset(datasetId, transport) {
    const creation = await post('datasets', {dataset_id: datasetId}, transport);
    // The existing API issues a one-use dataset bootstrap capability only on
    // creation. Existing datasets must already grant this service ingest access.
    // This is not a service identity/token and is never persisted in the browser.
    await post(`datasets/${encodeURIComponent(datasetId)}/ingest-grants/self`,
        creation.grant_token ? {grant_token: creation.grant_token} : {}, transport);
}

export async function grantReadyDatasetQuery(task, transport) {
    if (task?.status !== 'completed' || !task.dataset_id) return false;
    // Query grants require real indexed content. Keep that server-side readiness
    // rule and grant check rather than trying to grant before ingestion finishes.
    await post(`datasets/${encodeURIComponent(task.dataset_id)}/query-grants/self`, {}, transport);
    return true;
}
