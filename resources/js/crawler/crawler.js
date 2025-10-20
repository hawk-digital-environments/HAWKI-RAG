import { CheerioCrawler } from 'crawlee';
import { promises as fs } from 'fs';
import path from 'path';

// Import all utility modules
import { loadProcessedUrlsWithTimestamps } from './utilities/directoryManagement.js';
import { parseConfiguration, setupDirectoriesAndCrawlee } from './utilities/configurationSetup.js';
import { processUrlSources } from './utilities/urlSourceProcessor.js';
import { prepareRequests, buildCrawleeRequests } from './utilities/requestPreparation.js';
import { setupPageDirectory, extractTitleAndContent, saveTextContent } from './utilities/contentExtraction.js';
import { extractAndProcessImages } from './utilities/imageExtraction.js';
import { extractDate } from './utilities/dateExtraction.js';
import { 
    assemblePageData, 
    savePageData, 
    cleanupTempDirectory, 
    logCrawlerCompletion, 
    logSuccess 
} from './utilities/dataAssembly.js';

// Parse configuration and setup directories
const config = parseConfiguration();
const { crawlDir, tempDir } = setupDirectoriesAndCrawlee(config);

const MAX_CONCURRENCY = Math.max(1, Number(config.maxConcurrency ?? 4));
const MAX_REQUESTS_PER_MINUTE = config.maxRequestsPerMinute ? Math.max(1, Number(config.maxRequestsPerMinute)) : null;
const REQUEST_DELAY_MS = config.requestDelayMs !== null && config.requestDelayMs !== undefined
    ? Math.max(0, Number(config.requestDelayMs))
    : null;
const USE_RPM_THROTTLE = (!REQUEST_DELAY_MS || REQUEST_DELAY_MS === 0) && !!MAX_REQUESTS_PER_MINUTE;

// Initialize counter for unique IDs
let itemCounter = config.startFromIndex - 1;

/* ===== PDF scraping logic: begin ===== */

/** Fetch a URL as binary Buffer using native fetch. */
async function fetchBinary(url) {
    const res = await fetch(url, {
        headers: {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36',
            'Accept': 'application/pdf,application/octet-stream,*/*'
        }
    });
    if (!res.ok) {
        throw new Error(`HTTP ${res.status} for ${url}`);
    }
    const ab = await res.arrayBuffer();
    return Buffer.from(ab);
}

/**
 * Download all PDF links found on an HTML page into <pageDir>/files.
 * Returns an array of { url, local_path } of saved PDFs.
 */
async function downloadPdfsFromHtml($, pageUrl, pageDir, log) {
    const pdfHrefs = new Set(
        $('a[href$=".pdf"], a[href*=".pdf?"]')
            .map((_, a) => $(a).attr('href'))
            .get()
            .filter(Boolean)
    );

    if (pdfHrefs.size === 0) return [];

    const filesDir = path.join(pageDir, 'files');
    await fs.mkdir(filesDir, { recursive: true });

    const saved = [];
    for (const href of pdfHrefs) {
        try {
            const absolute = new URL(href, pageUrl).href;
            const buf = await fetchBinary(absolute);
            const pathname = new URL(absolute).pathname;
            const base = path.basename(pathname);
            const fileName = base && base.toLowerCase().endsWith('.pdf') ? base : (base || 'document.pdf');
            const outPath = path.join(filesDir, fileName);
            await fs.writeFile(outPath, buf);
            log.info(`Saved linked PDF: ${outPath}`);
            saved.push({ url: absolute, local_path: outPath });
        } catch (e) {
            log.warning(`Failed PDF download (${href}): ${e.message}`);
        }
    }
    return saved;
}

/**
 * If the current request is a PDF (by URL or content-type), download it directly.
 * Returns { handled: boolean } — if handled, the caller should return early.
 */
async function maybeHandleDirectPdf({ request, response, log }) {
    const url = request.url;
    const isPdfUrl = () => {
        try {
            const u = new URL(url);
            return u.pathname.toLowerCase().endsWith('.pdf');
        } catch {
            return url.toLowerCase().endsWith('.pdf');
        }
    };

    const contentType = response?.headers?.['content-type'] || response?.headers?.get?.('content-type');
    const isPdfHeader = typeof contentType === 'string' && contentType.toLowerCase().includes('application/pdf');

    if (!isPdfUrl() && !isPdfHeader) return { handled: false };

    // Setup page directory for this PDF request
    const directoryInfo = setupPageDirectory(request, crawlDir, itemCounter);
    const { formattedId, pageDir, newCounter, reused, updated } = directoryInfo;
    itemCounter = newCounter;

    if (reused) {
        log.info(`REUSING directory: ${formattedId}`);
    } else if (updated) {
        log.info(`UPDATING directory: ${formattedId}`);
    }

    // Download the PDF binary
    try {
        const buf = await fetchBinary(url);
        const pathname = new URL(url).pathname;
        const base = path.basename(pathname) || 'document.pdf';

        const filesDir = path.join(pageDir, 'files');
        await fs.mkdir(filesDir, { recursive: true });
        const outPath = path.join(filesDir, base.toLowerCase().endsWith('.pdf') ? base : `${base}.pdf`);
        await fs.writeFile(outPath, buf);
        log.info(`Saved direct PDF: ${outPath}`);

        // Prepare minimal metadata
        const titleFromName = base.replace(/\.pdf$/i, '') || 'PDF Document';
        const data = assemblePageData(titleFromName, url, null, [], null);
        data.pdfs = [{ url, local_path: outPath }];

        // Save the JSON sidecar
        savePageData(pageDir, formattedId, data, titleFromName, log);
    } catch (e) {
        log.error(`Failed direct PDF download (${url}): ${e.message}`);
    }

    return { handled: true };
}

/* ===== PDF scraping logic: end ===== */

// Main function to run the crawler
async function runCrawler() {
    logCrawlerCompletion(config);
    
    const { processedUrls: alreadyProcessedUrls, processedData } = loadProcessedUrlsWithTimestamps(crawlDir);
    const processedUrls = new Set(alreadyProcessedUrls);
    const throttleSummary = USE_RPM_THROTTLE
        ? `max ${MAX_REQUESTS_PER_MINUTE} requests/minute`
        : REQUEST_DELAY_MS && REQUEST_DELAY_MS > 0
            ? `~${Math.round(60000 / REQUEST_DELAY_MS)} requests/minute via ${REQUEST_DELAY_MS}ms delay`
            : 'no explicit per-minute throttle';
    console.log(`[Crawler] Throttle settings -> concurrency: ${MAX_CONCURRENCY}, ${throttleSummary}`);
    
    try {
        // Process URLs from various sources
        const { allUrlsToProcess, baseUrl } = await processUrlSources(
            config, 
            alreadyProcessedUrls, 
            processedData, 
            config.maxPages, 
            config.dateSelector
        );
        
        // Prepare requests with directory assignments
        const urlQueue = prepareRequests(
            allUrlsToProcess, 
            config.incompleteDirectories, 
            config.emptyDirectoriesToReuse
        );
        
        // Build Crawlee requests
        const requests = buildCrawleeRequests(urlQueue);
        
        if (requests.length === 0) {
            return;
        }
        
        const preNavigationHooks = [];
        if (REQUEST_DELAY_MS && REQUEST_DELAY_MS > 0) {
            preNavigationHooks.push(async () => {
                await new Promise((resolve) => setTimeout(resolve, REQUEST_DELAY_MS));
            });
        }

        // Create and configure crawler
        const crawler = new CheerioCrawler({
            maxRequestsPerCrawl: config.maxPages,
            maxConcurrency: MAX_CONCURRENCY,
            minConcurrency: 1,
            ...(USE_RPM_THROTTLE ? { maxRequestsPerMinute: MAX_REQUESTS_PER_MINUTE } : {}),
            ...(preNavigationHooks.length ? { preNavigationHooks } : {}),
            
            async requestHandler({ request, $, log, response, enqueueLinks }) {
                const url = request.url;

                // 0) Handle direct PDF responses/URLs up-front (binary fetch + save)
                const handledPdf = await maybeHandleDirectPdf({ request, response, log });
                if (handledPdf.handled) {
                    return;
                }
                
                // Skip 404 responses
                if (response && response.status === 404) {
                    log.info(`SKIPPING 404 Not Found: ${url}`);
                    return;
                }
                
                // Skip already processed URLs (unless reusing directory)
                if (processedUrls.has(url) && !request.userData.reuseDirectory) {
                    log.info(`Skipping already processed URL: ${url}`);
                    return;
                }
                
                // Skip empty pages
                const bodyText = $('body').clone()
                    .find('script, style, noscript').remove().end()
                    .text()
                    .trim();

                if (bodyText.length === 0) {
                    log.info(`SKIPPING empty body: ${url}`);
                    return;
                }
                
                processedUrls.add(url);
                log.info(`Processing: ${url}`);
                
                // Setup page directory
                const directoryInfo = setupPageDirectory(request, crawlDir, itemCounter);
                const { formattedId, pageDir, newCounter, reused, updated } = directoryInfo;
                itemCounter = newCounter;
                
                if (reused) {
                    log.info(`REUSING directory: ${formattedId}`);
                } else if (updated) {
                    log.info(`UPDATING directory: ${formattedId}`);
                }
                
                // Extract title and content
                const { title, content } = extractTitleAndContent($, url);
                
                // Save text content
                saveTextContent(pageDir, formattedId, title, content);
                
                // Extract and process images
                const { metaImageUrl, images } = await extractAndProcessImages(
                    $, 
                    url, 
                    baseUrl, 
                    config.imageExceptions, 
                    config.skipImages, 
                    pageDir, 
                    log
                );
                
                // Extract date if selector provided
                const date = extractDate($, config.dateSelector);
                
                // Download PDFs linked from this HTML page (if any)
                const savedPdfs = await downloadPdfsFromHtml($, url, pageDir, log);

                // Assemble and save data (now with PDFs list)
                const data = assemblePageData(title, url, metaImageUrl, images, date);
                if (savedPdfs.length > 0) {
                    data.pdfs = savedPdfs;
                }
                savePageData(pageDir, formattedId, data, title, log);
                // Enqueue internal links so the crawler continues beyond the seed.
                // This keeps the crawl constrained to the same domain.
                await enqueueLinks({
                    // 'same-domain' ensures we stay on ccj.fiu.edu when that is the seed host.
                    strategy: 'same-domain',
                    // Optionally tighten to a specific host/prefix with globs:
                    // globs: ['https://ccj.fiu.edu/**'],
                    // Skip fragment-only and typical tracking links
                    exclude: ['**#*', '**?*utm_*', '**?*fbclid=*']
                });
            },
            
            failedRequestHandler({ request, error, log }) {
                log.error(`Request failed (${request.url}): ${error.message}`);
            }
        });
        
        // Run the crawler
        await crawler.addRequests(requests);
        await crawler.run();
        
        // Cleanup and success
        cleanupTempDirectory(tempDir);
        logSuccess();
        
    } catch (error) {
        console.error(`Error during crawling: ${error.message}`);
        process.exit(1);
    }
}

// Run the crawler
runCrawler()
  .catch(error => {
    console.error('Crawler failed:', error);
    process.exit(1);
  });
