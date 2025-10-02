import fs from 'fs';
import path from 'path';
import { Sitemap } from 'crawlee';
import { getBaseUrl } from './urlHelpers.js';
import { validateAndPrepareUrls, normalizeTimestamp } from './dataValidation.js';
import { processUrlList } from './directoryManagement.js';

/**
 * Process URLs from various sources (config array, local file, sitemap, direct URL)
 */
export async function processUrlSources(config, alreadyProcessedUrls, processedData, maxPages, dateSelector) {
    const inputUrl = config.inputUrl;
    let baseUrl = getBaseUrl(inputUrl);
    let allUrlsToProcess = [];

    // Process URLs from config.urls array
    if (config.urls && Array.isArray(config.urls) && config.urls.length > 0) {
        console.log(`Processing local file with ${config.urls.length} URLs`);
        
        const validUrls = validateAndPrepareUrls(config.urls, 'local file');
        
        if (validUrls.length > 0) {
            baseUrl = getBaseUrl(validUrls[0]);
        }
        
        allUrlsToProcess = processUrlList(validUrls, alreadyProcessedUrls, processedData, 'local', 0, maxPages, dateSelector);
        
    // Process local file path
    } else if (!inputUrl.startsWith('http://') && !inputUrl.startsWith('https://')) {
        let filePath = inputUrl;
        if (!path.isAbsolute(filePath)) {
            filePath = path.resolve(process.cwd(), filePath);
        }
        
        if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
            console.log(`Processing local file: ${filePath}`);
            
            const fileContent = fs.readFileSync(filePath, 'utf8');
            const lines = fileContent.split('\n').filter(line => line.trim());
            
            const validUrls = validateAndPrepareUrls(lines, 'local file');
            
            if (validUrls.length > 0) {
                baseUrl = getBaseUrl(validUrls[0]);
            }
            
            allUrlsToProcess = processUrlList(validUrls, alreadyProcessedUrls, processedData, 'local', 0, maxPages, dateSelector);
        } else {
            console.error(`Local file not found: ${filePath}`);
            process.exit(1);
        }
        
    // Process sitemap or direct URL
    } else {
        const lowerUrl = inputUrl.toLowerCase();
        const isDefinitelySitemap = lowerUrl.includes('sitemap.xml') || 
                                   lowerUrl.includes('/sitemap') ||
                                   lowerUrl.endsWith('.xml') ||
                                   (lowerUrl.includes('sitemap') && lowerUrl.includes('.xml'));
        
        if (isDefinitelySitemap) {
            allUrlsToProcess = await processSitemap(inputUrl, alreadyProcessedUrls, processedData, maxPages, dateSelector);
        } else {
            console.log(`Processing direct URL: ${inputUrl}`);
            allUrlsToProcess = [{ url: inputUrl, isUpdate: false }];
        }
    }

    return { allUrlsToProcess, baseUrl };
}

/**
 * Process sitemap URLs with update detection
 */
async function processSitemap(inputUrl, alreadyProcessedUrls, processedData, maxPages, dateSelector) {
    console.log(`Processing sitemap: ${inputUrl}`);
    
    try {
        const result = await Sitemap.load(inputUrl);
        const sitemapUrls = result.urls;
        
        if (!sitemapUrls || sitemapUrls.length === 0) {
            console.error('Sitemap returned no URLs');
            process.exit(1);
        }
        
        console.log(`Loaded ${sitemapUrls.length} URLs from sitemap`);
        
        const sitemapUrlList = sitemapUrls.map(item => typeof item === 'object' ? item.url : item);
        const alreadyProcessedFromThisSitemap = sitemapUrlList.filter(url => alreadyProcessedUrls.has(url));
        
        console.log(`Found ${alreadyProcessedFromThisSitemap.length} URLs from this sitemap already processed`);
        
        if (alreadyProcessedFromThisSitemap.length >= sitemapUrls.length) {
            console.log(`All URLs from this sitemap have been processed`);
            return [];
        }
        
        const remainingCount = sitemapUrls.length - alreadyProcessedFromThisSitemap.length;
        console.log(`Will process ${remainingCount} remaining URLs from this sitemap`);
        
        const urlsToProcess = [];
        
        for (const item of sitemapUrls) {
            const url = typeof item === 'object' ? item.url : item;
            const sourceTimestamp = typeof item === 'object' ? item.lastmod : null;
            
            if (!alreadyProcessedUrls.has(url)) {
                urlsToProcess.push({ url, isUpdate: false });
            } else if (dateSelector && sourceTimestamp) {
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
        
        if (maxPages > 0 && urlsToProcess.length > maxPages) {
            const limitedUrls = urlsToProcess.slice(0, maxPages);
            console.log(`Limited to first ${maxPages} URLs (maxPages setting)`);
            return limitedUrls;
        }
        
        return urlsToProcess;
        
    } catch (error) {
        console.error(`Failed to load sitemap: ${error.message}`);
        process.exit(1);
    }
}
