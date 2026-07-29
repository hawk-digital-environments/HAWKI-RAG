function configuredBasePath() {
    const configured = document.querySelector('meta[name="hawki-api-base-path"]')?.content
        || window.hawkiPlayground?.apiBasePath
        || '/';
    const text = String(configured || '/').trim();

    if (!text || text === '/') {
        return '/';
    }

    return `${text.replace(/^\/?/, '/').replace(/\/?$/, '/')}`;
}

export function apiUrl(path) {
    const relative = String(path || '').replace(/^\/+/, '');
    const baseUrl = new URL(configuredBasePath(), window.location.origin);
    const apiBaseUrl = new URL('api/', baseUrl);

    return new URL(relative, apiBaseUrl).toString();
}

export function pageUrl(path) {
    const relative = String(path || '').replace(/^\/+/, '');

    return new URL(relative, new URL(configuredBasePath(), window.location.origin)).toString();
}
