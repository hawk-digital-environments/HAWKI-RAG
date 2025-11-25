/**
 * Normalize timestamps for comparison
 */
export function normalizeTimestamp(timestamp) {
    if (!timestamp) return null;
    
    try {
        const date = new Date(timestamp);
        if (isNaN(date.getTime())) return timestamp;
        
        return date.toISOString();
    } catch (error) {
        return timestamp;
    }
}

/**
 * Validate and prepare URLs from various sources
 */
export function validateAndPrepareUrls(rawUrls, sourceType) {
    const validUrls = rawUrls.filter(url => {
        const trimmed = typeof url === 'object' ? url.url : url;
        return trimmed && trimmed.trim() && 
               (trimmed.startsWith('http://') || trimmed.startsWith('https://'));
    });
    
    if (validUrls.length === 0) {
        console.error(`No valid URLs found in ${sourceType}`);
        process.exit(1);
    }
    
    console.log(`Found ${validUrls.length} valid URLs in ${sourceType}`);
    return validUrls;
}
