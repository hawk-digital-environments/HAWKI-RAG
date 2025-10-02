/**
 * Extract base URL from a URL string
 */
export function getBaseUrl(urlString) {
    try {
        const parsedUrl = new URL(urlString);
        return `${parsedUrl.protocol}//${parsedUrl.hostname}`;
    } catch (e) {
        return '';
    }
}

/**
 * Resolve relative URLs to absolute URLs
 */
export function resolveUrl(relativeUrl, pageUrl, baseUrl) {
    if (!relativeUrl) return null;
    
    try {
        const url = new URL(relativeUrl, pageUrl);
        return url.href;
    } catch (e) {
        if (relativeUrl.startsWith('/')) {
            return `${baseUrl}${relativeUrl}`;
        } else if (relativeUrl.startsWith('../')) {
            const urlParts = pageUrl.split('/');
            urlParts.pop();
            
            let pathParts = relativeUrl.split('/');
            while (pathParts[0] === '..') {
                urlParts.pop();
                pathParts.shift();
            }
            
            return `${urlParts.join('/')}/` + pathParts.join('/');
        } else if (!relativeUrl.startsWith('http')) {
            return `${baseUrl}/${relativeUrl}`;
        }
        
        return relativeUrl;
    }
}

/**
 * Get base name from URL
 */
export function getBaseName(url) {
    if (!url) return '';
    const parts = url.split('/');
    return parts[parts.length - 1];
}
