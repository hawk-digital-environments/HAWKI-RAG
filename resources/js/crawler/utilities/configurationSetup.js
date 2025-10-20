import fs from 'fs';
import path from 'path';
import { Configuration } from 'crawlee';
import { emptyDirectory } from './fileSystem.js';

/**
 * Parse and validate crawler configuration
 */
export function parseConfiguration() {
    const args = process.argv.slice(2);
    const config = JSON.parse(args[0]);
    
    // Validate required configuration
    if (!config.url) {
        console.error('Starting URL is required');
        process.exit(1);
    }

    const maxConcurrency = Number.isFinite(Number(config.maxConcurrency))
        ? Math.max(1, Number(config.maxConcurrency))
        : 4;
    const maxRequestsPerMinute = Number.isFinite(Number(config.maxRequestsPerMinute))
        ? Math.max(1, Number(config.maxRequestsPerMinute))
        : 60;
    const requestDelayMs = config.requestDelayMs !== undefined && config.requestDelayMs !== null && config.requestDelayMs !== ''
        ? Math.max(0, Number(config.requestDelayMs))
        : null;
    
    return {
        outputDir: config.outputDir,
        maxPages: config.maxPages || 100,
        label: config.label || 'default',
        skipImages: config.skipImages || false,
        imageExceptions: config.imageExceptions || [],
        startFromIndex: config.startFromIndex || 1,
        dateSelector: config.dateSelector || null,
        incompleteDirectories: config.incompleteDirectories || {},
        emptyDirectoriesToReuse: config.emptyDirectoriesToReuse || [],
        inputUrl: config.url,
        urls: config.urls,
        maxConcurrency,
        maxRequestsPerMinute,
        requestDelayMs,
    };
}

/**
 * Setup directories and crawlee configuration
 */
export function setupDirectoriesAndCrawlee(config) {
    // Create output directory structure
    const crawlDir = path.join(config.outputDir, config.label);
    const tempDir = path.join(config.outputDir, 'temp-' + Date.now());
    
    // Setup crawl directory
    if (config.startFromIndex === 1) {
        if (fs.existsSync(crawlDir)) {
            emptyDirectory(crawlDir);
        } else {
            fs.mkdirSync(crawlDir, { recursive: true });
        }
    } else {
        if (!fs.existsSync(crawlDir)) {
            fs.mkdirSync(crawlDir, { recursive: true });
        }
    }
    
    // Setup temp directory for Crawlee
    if (!fs.existsSync(tempDir)) {
        fs.mkdirSync(tempDir, { recursive: true });
    }
    
    // Configure Crawlee
    process.env.CRAWLEE_STORAGE_DIR = tempDir;
    Configuration.getGlobalConfig().set('storageDir', tempDir);
    Configuration.getGlobalConfig().set('purgeOnStart', true);
    
    return { crawlDir, tempDir };
}
