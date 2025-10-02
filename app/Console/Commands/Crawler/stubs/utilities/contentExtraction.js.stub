import fs from 'fs';
import path from 'path';

/**
 * Setup page directory and determine directory info
 */
export function setupPageDirectory(request, crawlDir, itemCounter) {
    let pageId, formattedId, pageDir;
    
    if (request.userData.reuseDirectory) {
        pageId = request.userData.reuseDirectory;
        formattedId = String(pageId).padStart(5, '0');
        pageDir = path.join(crawlDir, formattedId);
        
        if (!fs.existsSync(pageDir)) {
            fs.mkdirSync(pageDir, { recursive: true });
        }
        
        return { pageId, formattedId, pageDir, newCounter: itemCounter, reused: true };
    } else if (request.userData.isUpdate) {
        formattedId = request.userData.existingDirectory;
        pageId = parseInt(formattedId, 10);
        pageDir = request.userData.existingPath;
        
        return { pageId, formattedId, pageDir, newCounter: itemCounter, updated: true };
    } else {
        pageId = itemCounter + 1;
        formattedId = String(pageId).padStart(5, '0');
        pageDir = path.join(crawlDir, formattedId);
        
        if (!fs.existsSync(pageDir)) {
            fs.mkdirSync(pageDir, { recursive: true });
        }
        
        return { pageId, formattedId, pageDir, newCounter: pageId, created: true };
    }
}

/**
 * Extract title and main content from page
 */
export function extractTitleAndContent($, url) {
    const title = $('title').text() || url;
    
    let content = '';
    const contentSelectors = [
        'main', 
        'article', 
        '.content', 
        '#content',
        '.main-content',
        '.entry-content',
        '.post-content',
        'body'
    ];
    
    for (const selector of contentSelectors) {
        if ($(selector).length) {
            const clone = $(selector).clone();
            clone.find('script, style, noscript, iframe').remove();
            content = clone.text();
            break;
        }
    }
    
    content = content
        .replace(/\s+/g, ' ')
        .replace(/^\s+|\s+$/g, '')
        .trim();
    
    return { title, content };
}

/**
 * Save text content to file
 */
export function saveTextContent(pageDir, formattedId, title, content) {
    try {
        const textFileContent = `${title}\n${content}`;
        fs.writeFileSync(
            path.join(pageDir, `site_${formattedId}.txt`),
            textFileContent
        );
    } catch (error) {
        console.error(`Failed to write text file: ${error.message}`);
    }
}
