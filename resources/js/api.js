// HAWKI authorizes browser access at the server-side UI service boundary.
// Its service JWT is never returned to browser JavaScript.
export async function apiFetch(input, init = {}) {
    const headers = new Headers(init.headers || {});
    headers.set('X-Hawki-Browser', '1');
    const response = await globalThis.fetch(input, {
        ...init, headers, credentials: 'same-origin',
    });
    if (!response.ok && response.headers.get('content-type')?.includes('application/json')) {
        const payload = await response.clone().json().catch(() => null);
        if (payload?.detail) {
            const detail = payload.detail;
            const message = typeof detail === 'string' ? detail.replaceAll('_', ' ')
                : detail.message || detail.error || `Request failed (${response.status})`;
            return new Response(JSON.stringify({...payload, message, error: detail.error || payload.error}), {
                status: response.status, headers: response.headers,
            });
        }
    }
    return response;
}
