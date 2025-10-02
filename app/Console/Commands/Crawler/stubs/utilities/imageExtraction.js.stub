import fs from 'fs';
import path from 'path';
import { resolveUrl, getBaseName } from './urlHelpers.js';
import { isValidImageUrl, ensureImageExtension, downloadImage } from './imageProcessing.js';

/**
 * Extract and process all images from a page
 */
export async function extractAndProcessImages($, url, baseUrl, imageExceptions, skipImages, pageDir, log) {
    const images = [];
    
    // Extract meta image
    const metaImage = $('meta[property="og:image"]').attr('content');
    let metaImageUrl = null;
    
    if (metaImage) {
        metaImageUrl = resolveUrl(metaImage, url, baseUrl);
    }
    
    // Process picture elements for responsive images
    extractFromPictureElements($, url, baseUrl, imageExceptions, images);
    
    // Process regular img elements
    extractFromImageElements($, url, baseUrl, imageExceptions, images);
    
    // Download images if not skipped
    let actuallyDownloaded = [];
    if (!skipImages && (images.length > 0 || metaImageUrl)) {
        actuallyDownloaded = await downloadAllImages(images, metaImageUrl, pageDir, log);
    }
    
    return {
        metaImageUrl,
        images: skipImages ? images : actuallyDownloaded
    };
}

/**
 * Extract images from picture elements
 */
function extractFromPictureElements($, url, baseUrl, imageExceptions, images) {
    $('body picture').each((_, picture) => {
        let skipPicture = false;
        if (imageExceptions && imageExceptions.length > 0) {
            for (const selector of imageExceptions) {
                if ($(picture).parents(selector).length > 0) {
                    skipPicture = true;
                    break;
                }
            }
        }
        
        if (skipPicture) {
            return;
        }
        
        const sources = $(picture).find('source');
        
        if (sources.length > 0) {
            const lastSource = $(sources[sources.length - 1]);
            let srcset = lastSource.attr('srcset') || '';
            
            if (srcset) {
                if (srcset.includes(',')) {
                    const srcsetItems = srcset.split(',');
                    let smallestSrc = '';
                    let smallestWidth = Number.MAX_SAFE_INTEGER;
                    
                    srcsetItems.forEach(item => {
                        const parts = item.trim().split(/\s+/);
                        if (parts.length >= 2) {
                            const url = parts[0];
                            const width = parseInt(parts[1].replace(/[^\d]/g, ''), 10);
                            
                            if (width < smallestWidth) {
                                smallestWidth = width;
                                smallestSrc = url;
                            }
                        } else if (parts.length === 1 && !smallestSrc) {
                            smallestSrc = parts[0];
                        }
                    });
                    
                    srcset = smallestSrc || srcset;
                }
                
                let fullUrl = resolveUrl(srcset, url, baseUrl);
                fullUrl = ensureImageExtension(fullUrl);
                
                if (isValidImageUrl(fullUrl)) {
                    if (!images.includes(fullUrl)) {
                        images.push(fullUrl);
                    }
                }
            }
        } else {
            const img = $(picture).find('img');
            if (img.length > 0) {
                const src = $(img).attr('src');
                if (src) {
                    let fullUrl = resolveUrl(src, url, baseUrl);
                    fullUrl = ensureImageExtension(fullUrl);
                    
                    if (isValidImageUrl(fullUrl)) {
                        if (!images.includes(fullUrl)) {
                            images.push(fullUrl);
                        }
                    }
                }
            }
        }
    });
}

/**
 * Extract images from regular img elements
 */
function extractFromImageElements($, url, baseUrl, imageExceptions, images) {
    $('body img').each((_, img) => {
        if ($(img).closest('picture').length > 0) {
            return;
        }
        
        let skipImage = false;
        if (imageExceptions && imageExceptions.length > 0) {
            for (const selector of imageExceptions) {
                if ($(img).parents(selector).length > 0) {
                    skipImage = true;
                    break;
                }
            }
        }
        
        if (skipImage) {
            return;
        }
        
        const src = $(img).attr('src');
        if (!src) return;
        
        const fullUrl = resolveUrl(src, url, baseUrl);
        
        if (!fullUrl) {
            return;
        }
        
        const downloadUrl = ensureImageExtension(fullUrl);
        
        if (isValidImageUrl(downloadUrl) && !images.includes(downloadUrl)) {
            images.push(downloadUrl);
        }
    });
}

/**
 * Download all images to the images directory
 */
async function downloadAllImages(images, metaImageUrl, pageDir, log) {
    const imagesDir = path.join(pageDir, 'images');
    if (!fs.existsSync(imagesDir)) {
        fs.mkdirSync(imagesDir, { recursive: true });
    }
    
    const actuallyDownloaded = [];
    
    for (const imageUrl of images) {
        try {
            const result = await downloadImage(imageUrl, imagesDir);
            if (result) {
                actuallyDownloaded.push(result.cleanedUrl);
            }
        } catch (error) {
            console.error(`Failed to download image ${imageUrl}: ${error.message}`);
        }
    }
    
    if (metaImageUrl) {
        try {
            await downloadImage(metaImageUrl, imagesDir);
        } catch (error) {
            console.error(`Failed to download meta image ${metaImageUrl}: ${error.message}`);
        }
    }
    
    log.info(`Downloaded ${actuallyDownloaded.length} content images`);
    return actuallyDownloaded;
}
