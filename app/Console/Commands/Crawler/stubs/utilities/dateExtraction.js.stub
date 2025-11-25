/**
 * Extract date from page using the provided selector
 */
export function extractDate($, dateSelector) {
    if (!dateSelector) {
        return null;
    }
    
    const dateElement = $(dateSelector);
    if (!dateElement.length) {
        return null;
    }
    
    let rawDate = null;
    
    // Extract date from meta tag or text content
    if (dateElement.is('meta')) {
        rawDate = dateElement.attr('content');
    } else {
        rawDate = dateElement.text().trim();
    }
    
    if (!rawDate) {
        return null;
    }
    
    // Process date based on format
    if (rawDate.includes('T') && (rawDate.includes('+') || rawDate.includes('Z'))) {
        try {
            const parsedDate = new Date(rawDate);
            
            if (!isNaN(parsedDate.getTime())) {
                return parsedDate.toLocaleString('sv-SE', {
                    timeZone: 'Europe/Berlin'
                }).replace('T', ' ');
            } else {
                return rawDate;
            }
        } catch (error) {
            return rawDate;
        }
    } else {
        return rawDate;
    }
}
