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
    let relative = String(path || '').replace(/^\/+/, '');
    const aliases = {
        'rag/stats': 'ops/stats',
        'rag/monitor': 'ops/health',
        'rag/health': 'health',
        'pipeline/health': 'ops/health',
        'health/system-gate': 'ops/health',
        'settings/config': 'ops/config',
        'pipeline/controller/files': 'documents',
        'rag/neo4j/clear': 'ops/graph/clear',
    };
    const [pathname, query] = relative.split('?', 2);
    relative = aliases[pathname] || pathname.replace(/^rag\/neo4j\/graph\//, 'ops/graph/');
    if (query !== undefined) relative += `?${query}`;
    const baseUrl = new URL(configuredBasePath(), window.location.origin);
    const apiBaseUrl = new URL('ui-api/v1/', baseUrl);

    return new URL(relative, apiBaseUrl).toString();
}

export function pageUrl(path) {
    const relative = String(path || '').replace(/^\/+/, '');

    return new URL(relative, new URL(configuredBasePath(), window.location.origin)).toString();
}
