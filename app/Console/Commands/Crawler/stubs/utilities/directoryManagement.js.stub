import fs from 'fs';
import path from 'path';
import { normalizeTimestamp } from './dataValidation.js';

/**
 * Check if a directory is complete with all required files (Private function)
 */
function isDirectoryComplete(dirPath, directoryName) {
    try {
        if (!fs.existsSync(dirPath) || !fs.statSync(dirPath).isDirectory()) {
            return false;
        }

        const requiredFiles = [
            `site_${directoryName}.txt`,
            `data_${directoryName}.json`
        ];
        
        for (const file of requiredFiles) {
            const filePath = path.join(dirPath, file);
            if (!fs.existsSync(filePath)) {
                return false;
            }
            
            const stats = fs.statSync(filePath);
            if (stats.size === 0) {
                return false;
            }
        }
        
        // Validate JSON file content
        const jsonFile = path.join(dirPath, `data_${directoryName}.json`);
        try {
            const jsonContent = fs.readFileSync(jsonFile, 'utf8');
            const decoded = JSON.parse(jsonContent);
            
            if (!decoded.title || !decoded.page_url || 
                !Array.isArray(decoded.title) || !Array.isArray(decoded.page_url) ||
                decoded.title.length === 0 || decoded.page_url.length === 0) {
                return false;
            }
            
            if (decoded.images && Array.isArray(decoded.images) && decoded.images.length > 0) {
                const imagesDir = path.join(dirPath, 'images');
                if (!fs.existsSync(imagesDir)) {
                    return false;
                }
                
                const imageFiles = fs.readdirSync(imagesDir).filter(file => 
                    file.match(/\.(jpg|jpeg|png|gif|webp)$/i)
                );
                
                if (imageFiles.length === 0 && decoded.images.length > 0) {
                    return false;
                }
            }
            
        } catch (error) {
            return false;
        }
        
        return true;
        
    } catch (error) {
        return false;
    }
}

/**
 * Extract URL from incomplete directory if possible (Private function)
 */
function extractUrlFromIncompleteDirectory(dirPath, directoryName) {
    try {
        const jsonFile = path.join(dirPath, `data_${directoryName}.json`);
        if (fs.existsSync(jsonFile)) {
            const jsonContent = fs.readFileSync(jsonFile, 'utf8');
            const decoded = JSON.parse(jsonContent);
            if (decoded.page_url && Array.isArray(decoded.page_url) && decoded.page_url.length > 0) {
                return decoded.page_url[0];
            }
        }
        
        return null;
    } catch (error) {
        return null;
    }
}

/**
 * Load already processed URLs with comprehensive validation
 */
export function loadProcessedUrlsWithTimestamps(crawlDir) {
    const processedUrls = new Set();
    const processedData = new Map();
    const incompleteDirectoryData = [];
    
    if (!fs.existsSync(crawlDir)) {
        return { processedUrls, processedData, incompleteDirectoryData };
    }
    
    console.log(`Scanning existing directories for completeness...`);
    
    const items = fs.readdirSync(crawlDir);
    let completeCount = 0;
    let incompleteCount = 0;
    
    for (const item of items) {
        const itemPath = path.join(crawlDir, item);
        
        if (fs.statSync(itemPath).isDirectory() && /^\d{5}$/.test(item)) {
            const directoryNumber = parseInt(item, 10);
            
            if (isDirectoryComplete(itemPath, item)) {
                const jsonFile = path.join(itemPath, `data_${item}.json`);
                
                try {
                    const data = JSON.parse(fs.readFileSync(jsonFile, 'utf8'));
                    if (data.page_url && data.page_url.length > 0) {
                        const url = data.page_url[0];
                        const timestamp = (data.date && data.date.length > 0) ? data.date[0] : null;
                        
                        processedUrls.add(url);
                        processedData.set(url, {
                            directory: item,
                            timestamp: timestamp,
                            path: itemPath
                        });
                        completeCount++;
                    }
                } catch (error) {
                    const extractedUrl = extractUrlFromIncompleteDirectory(itemPath, item);
                    incompleteDirectoryData.push({
                        directoryNumber,
                        url: extractedUrl,
                        path: itemPath,
                        reason: 'corrupted JSON'
                    });
                    incompleteCount++;
                }
            } else {
                const extractedUrl = extractUrlFromIncompleteDirectory(itemPath, item);
                incompleteDirectoryData.push({
                    directoryNumber,
                    url: extractedUrl,
                    path: itemPath,
                    reason: 'incomplete files'
                });
                incompleteCount++;
            }
        }
    }
    
    console.log(`Complete directories: ${completeCount}`);
    console.log(`Incomplete directories: ${incompleteCount}`);
    
    return { processedUrls, processedData, incompleteDirectoryData };
}

/**
 * Process URLs from local files with offset support
 */
export function processUrlList(urlList, alreadyProcessedUrls, processedData, sourceType = 'local', continueOffset = 0, maxPages, dateSelector) {
    let urlsToCheck = urlList;
    if (continueOffset > 0) {
        urlsToCheck = urlList.slice(continueOffset);
        console.log(`Skipping first ${continueOffset} URLs (continue offset)`);
    }
    
    console.log(`Processing ${urlsToCheck.length} URLs from ${sourceType}`);
    
    const urlsToProcess = [];
    
    for (const item of urlsToCheck) {
        const url = typeof item === 'object' ? item.url : item;
        const sourceTimestamp = typeof item === 'object' ? item.lastmod : null;
        
        if (!alreadyProcessedUrls.has(url)) {
            urlsToProcess.push({ url, isUpdate: false });
        } else if (dateSelector && sourceTimestamp && sourceType === 'sitemap') {
            const existingData = processedData.get(url);
            const existingTimestamp = existingData?.timestamp;
            
            const normalizedSourceTime = normalizeTimestamp(sourceTimestamp);
            const normalizedExistingTime = normalizeTimestamp(existingTimestamp);
            
            if (normalizedSourceTime && normalizedExistingTime && 
                normalizedSourceTime !== normalizedExistingTime) {
                urlsToProcess.push({ 
                    url, 
                    isUpdate: true, 
                    existingDirectory: existingData.directory,
                    existingPath: existingData.path 
                });
            }
        }
    }
    
    let finalUrlsToProcess = urlsToProcess;
    if (maxPages > 0 && urlsToProcess.length > maxPages) {
        finalUrlsToProcess = urlsToProcess.slice(0, maxPages);
        console.log(`Limited to first ${maxPages} URLs (maxPages setting)`);
    }
    
    return finalUrlsToProcess;
}
