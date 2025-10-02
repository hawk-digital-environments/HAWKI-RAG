import fs from 'fs';
import path from 'path';
import axios from 'axios';

/**
 * Check if URL is a valid image
 */
export function isValidImageUrl(url) {
    if (url.includes('piwik.php') || 
        url.includes('tracking') || 
        url.includes('analytics') ||
        url.includes('.php') ||
        url.includes('.js') ||
        url.includes('.css')) {
        return false;
    }
    
    return true;
}

/**
 * Ensure content images have extensions for download
 */
export function ensureImageExtension(url) {
    const lowercaseUrl = url.toLowerCase();
    const imageExtensions = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg', '.bmp'];
    if (imageExtensions.some(ext => lowercaseUrl.endsWith(ext))) {
        return url;
    }
    
    return `${url}.jpg`;
}

/**
 * Download image function with WebP to JPG conversion
 */
export async function downloadImage(imageUrl, outputPath) {
    try {
        const cleanedUrl = imageUrl.split('?')[0];
        
        let filename = path.basename(cleanedUrl);
        let resultUrl = cleanedUrl;
        
        const response = await axios({
            method: 'GET',
            url: imageUrl,
            responseType: 'stream',
            timeout: 30000,
            validateStatus: status => status >= 200 && status < 300
        });
        
        const contentType = response.headers['content-type'] ? response.headers['content-type'].toLowerCase() : '';
        
        if (!filename.includes('.') && contentType) {
            let extension = '.jpg';
            
            if (contentType.includes('image/png')) extension = '.png';
            else if (contentType.includes('image/gif')) extension = '.gif';
            else if (contentType.includes('image/webp')) extension = '.webp';
            else if (contentType.includes('image/svg+xml')) extension = '.svg';
            else if (contentType.includes('image/bmp')) extension = '.bmp';
            
            filename = filename + extension;
            resultUrl = cleanedUrl + extension;
        }
        
        const chunks = [];
        for await (const chunk of response.data) {
            chunks.push(chunk);
        }
        const buffer = Buffer.concat(chunks);
        
        // Detect WebP by magic bytes
        const isActuallyWebP = buffer.length >= 12 && 
                              buffer.slice(0, 4).toString() === 'RIFF' && 
                              buffer.slice(8, 12).toString() === 'WEBP';
        
        // Handle double extension pattern (e.g., image.jpg.webp)
        const hasDoubleWebPExtension = filename.toLowerCase().match(/\.(jpg|jpeg|png|gif)\.webp$/i);
        
        if (contentType.includes('image/webp') || 
            filename.toLowerCase().endsWith('.webp') || 
            hasDoubleWebPExtension ||
            isActuallyWebP) {
            
            let baseFilename;
            if (hasDoubleWebPExtension) {
                baseFilename = filename.replace(/\.(jpg|jpeg|png|gif)\.webp$/i, '');
            } else {
                baseFilename = filename.replace(/\.(webp|jpg|jpeg|png|gif)$/i, '');
            }
            
            filename = baseFilename + '.jpg';
            
            if (hasDoubleWebPExtension) {
                const baseResultUrl = resultUrl.replace(/\.(jpg|jpeg|png|gif)\.webp$/i, '');
                resultUrl = baseResultUrl + '.jpg';
            } else {
                const baseResultUrl = resultUrl.replace(/\.(webp|jpg|jpeg|png|gif)$/i, '');
                resultUrl = baseResultUrl + '.jpg';
            }
            
            try {
                const sharp = (await import('sharp')).default;
                const savePath = path.join(outputPath, filename);
                
                const jpegBuffer = await sharp(buffer)
                    .jpeg({ 
                        quality: 90,
                        progressive: true,
                        mozjpeg: true
                    })
                    .toBuffer();
                
                fs.writeFileSync(savePath, jpegBuffer);
                
                const verificationBuffer = fs.readFileSync(savePath);
                const isJpeg = verificationBuffer.slice(0, 3).toString('hex') === 'ffd8ff';
                
                if (!isJpeg) {
                    console.warn(`Conversion may have failed: File doesn't have JPEG magic bytes`);
                }
                
                return { filename, cleanedUrl: resultUrl };
                
            } catch (sharpError) {
                console.error(`Sharp conversion failed: ${sharpError.message}`);
                throw new Error(`WebP conversion failed: ${sharpError.message}`);
            }
        }
        
        const savePath = path.join(outputPath, filename);
        fs.writeFileSync(savePath, buffer);
        
        return { filename, cleanedUrl: resultUrl };
    } catch (error) {
        console.error(`Failed to download image ${imageUrl}: ${error.message}`);
        return null;
    }
}
