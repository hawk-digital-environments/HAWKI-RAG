const runningStatuses = new Set(['running', 'processing']);
const completedStatuses = new Set(['completed']);

function statusText(status, fallback = 'unknown') {
    const value = String(status || '').trim();
    return value || fallback;
}

function iconClass(status) {
    const value = statusText(status).toLowerCase();
    if (runningStatuses.has(value)) return 'is-spinner';
    if (completedStatuses.has(value)) return 'is-check';
    return '';
}

export function renderStatusIndicator(element, status, fallback = 'unknown') {
    if (!element) return;

    const label = statusText(status, fallback);
    const icon = iconClass(status);
    element.replaceChildren();
    element.title = label;
    element.setAttribute('aria-label', label);

    if (!icon) {
        element.classList.remove('has-status-icon');
        element.textContent = label;
        return;
    }

    element.classList.add('has-status-icon');
    const marker = document.createElement('span');
    marker.className = `status-icon ${icon}`;
    marker.setAttribute('aria-hidden', 'true');
    element.appendChild(marker);
}
