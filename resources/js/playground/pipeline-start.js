import {apiFetch as fetch} from '../api.js';
// Retain a frozen submission across uncertain HTTP outcomes. A successful start
// resolves it; the next intentional start receives a fresh key and body.
export function createPipelineStarter({ url, payload, csrfToken, fetcher = fetch, key = () => crypto.randomUUID() }) {
    const pending = new Map();
    return async function start(task) {
        let submission = pending.get(task.id);
        if (!submission) {
            submission = { key: key(), body: JSON.stringify(payload(task)) };
            pending.set(task.id, submission);
        }
        const response = await fetcher(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'Idempotency-Key': submission.key,
            },
            body: submission.body,
        });
        const data = await response.json();
        if (!response.ok || !data.success || !data.task_id) {
            throw new Error(data.message || `Pipeline task start failed (${response.status})`);
        }
        if (pending.get(task.id) === submission) pending.delete(task.id);
        return data;
    };
}
