/**
 * Prepare crawl requests with directory assignments and reuse logic
 */
export function prepareRequests(allUrlsToProcess, incompleteDirectories, emptyDirectoriesToReuse) {
    let urlQueue = [];
    let directoryAssignments = new Map();
    
    // Handle incomplete directories by reassigning URLs to their original directory numbers
    if (Object.keys(incompleteDirectories).length > 0) {
        console.log(`Re-assigning ${Object.keys(incompleteDirectories).length} URLs to their original directories`);
        
        for (const [dirNum, url] of Object.entries(incompleteDirectories)) {
            directoryAssignments.set(url, parseInt(dirNum, 10));
            urlQueue.push({
                url: url,
                isUpdate: false,
                reuseDirectory: parseInt(dirNum, 10)
            });
        }
    }
    
    const urls = allUrlsToProcess.map(item => item.url);
    
    // Reuse empty directories for new URLs
    if (emptyDirectoriesToReuse.length > 0) {
        console.log(`Reusing ${emptyDirectoriesToReuse.length} empty directories for new URLs`);
        
        const sortedEmptyDirs = emptyDirectoriesToReuse.sort((a, b) => a - b);
        let assignmentCount = 0;
        
        for (const url of urls) {
            if (directoryAssignments.has(url)) {
                continue;
            }
            
            if (assignmentCount < sortedEmptyDirs.length) {
                const dirNum = sortedEmptyDirs[assignmentCount];
                directoryAssignments.set(url, dirNum);
                
                urlQueue.push({
                    url: url,
                    isUpdate: false,
                    reuseDirectory: dirNum
                });
                
                assignmentCount++;
            } else {
                urlQueue.push({
                    url: url,
                    isUpdate: false
                });
            }
        }
        
        console.log(`Assigned ${assignmentCount} URLs to empty directories`);
    } else {
        urlQueue = allUrlsToProcess;
    }
    
    return urlQueue;
}

/**
 * Build Crawlee requests from URL queue
 */
export function buildCrawleeRequests(urlQueue) {
    if (!urlQueue || urlQueue.length === 0) {
        console.log('No URLs to process - all work complete');
        return [];
    }
    
    const urls = urlQueue.map(item => item.url);
    console.log(`Processing ${urls.length} URLs`);
    
    const requests = [];
    for (const urlData of urlQueue) {
        if (typeof urlData === 'object' && urlData.url) {
            requests.push({
                url: urlData.url.trim(),
                userData: { ...urlData, isInitialRequest: true }
            });
        } else if (typeof urlData === 'string' && urlData.trim() !== '') {
            requests.push({
                url: urlData.trim(),
                userData: { isInitialRequest: true }
            });
        }
    }
    
    if (requests.length === 0) {
        console.error('No valid requests could be created');
        process.exit(1);
    }
    
    return requests;
}
