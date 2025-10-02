import fs from 'fs';
import { saveData, emptyDirectory } from './fileSystem.js';

/**
 * Assemble final data structure for saving
 */
export function assemblePageData(title, url, metaImageUrl, images, date) {
    const data = {
        title: [title],
        page_url: [url],
        meta_img_url: metaImageUrl ? [metaImageUrl] : [],
        images: images
    };
    
    data.date = date ? [date] : [];
    
    return data;
}

/**
 * Save page data and log completion
 */
export function savePageData(pageDir, formattedId, data, title, log) {
    saveData(pageDir, formattedId, data);
    log.info(`Saved data for: ${title}`);
}

/**
 * Cleanup temporary directories
 */
export function cleanupTempDirectory(tempDir) {
    try {
        if (fs.existsSync(tempDir)) {
            emptyDirectory(tempDir);
            fs.rmdirSync(tempDir);
        }
    } catch (error) {
        console.error(`Error cleaning up temp directory: ${error.message}`);
    }
}

/**
 * Log crawler completion status
 */
export function logCrawlerCompletion(config) {
    console.log(`Starting crawler with ${config.skipImages ? 'disabled' : 'enabled'} image downloads`);
    if (config.startFromIndex > 1) {
        console.log(`Continuing from directory index: ${config.startFromIndex}`);
    }
}

/**
 * Log final success message
 */
export function logSuccess() {
    console.log('Crawling completed successfully!');
}
