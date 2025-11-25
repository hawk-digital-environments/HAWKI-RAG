# Image Download Service Documentation

## Overview

The **Image Download Service** provides robust and intelligent image downloading capabilities for the crawler. It solves the common problem of images having missing or incorrect file extensions in their URLs by implementing smart extension detection and retry logic.

**Location**: `resources/js/crawler/utilities/imageDownloadService.js`

---

## The Problem It Solves

### Issue

Many websites serve images through URLs without file extensions:

```
❌ https://projekte.g.hawk.de/medien/688b2d07d93fd/gallery/688b388dda2a3
✅ https://projekte.g.hawk.de/medien/688b2d07d93fd/gallery/688b388dda2a3.jpg
```

When the crawler attempts to download these URLs, the server returns:
- **HTTP 500 errors** (server expects extension)
- **HTTP 404 errors** (resource not found without extension)
- **Incorrect content-type** headers

### Solution

The Image Download Service implements a **multi-strategy approach**:

1. **HEAD Request Probing** - Sends lightweight HEAD request to detect content-type
2. **Extension Attempts** - Tries common extensions (.jpg, .jpeg, .png, .webp, .gif, .svg)
3. **Retry Logic** - Implements configurable retry attempts with delays
4. **Proper HTTP Headers** - Sends User-Agent and Accept headers that servers expect
5. **WebP Conversion** - Automatically converts WebP images to JPEG format

---

## Key Features

### ✅ Smart Extension Detection

The service uses multiple strategies to determine the correct image extension:

#### 1. Direct Download (URL has extension)
```javascript
https://example.com/image.jpg
// ↓ Direct download attempt
```

#### 2. HEAD Request Probing
```javascript
https://example.com/image
// ↓ HEAD request to detect content-type
// ← Content-Type: image/jpeg
// ↓ Download with .jpg extension
```

#### 3. Progressive Extension Attempts
```javascript
https://example.com/image
// ↓ Try .jpg
// ✗ 404
// ↓ Try .jpeg
// ✗ 404
// ↓ Try .png
// ✓ Success!
```

### ✅ Retry Logic

**Configuration**:
```javascript
const DOWNLOAD_CONFIG = {
    timeout: 30000,        // 30 seconds
    maxRetries: 3,         // 3 attempts
    retryDelay: 1000,      // 1 second delay between attempts
    commonExtensions: ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.svg']
};
```

**Flow**:
1. Attempt 1: Direct download or with probed extension
2. Wait 1 second
3. Attempt 2: Try next extension
4. Wait 1 second
5. Attempt 3: Try next extension
6. Return null if all attempts fail

### ✅ Proper HTTP Headers

The service sends headers that servers expect:

```javascript
{
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ...',
    'Accept': 'image/webp,image/apng,image/*,*/*;q=0.8',
    'Accept-Language': 'en-US,en;q=0.9',
    'Cache-Control': 'no-cache',
    'Pragma': 'no-cache'
}
```

**Why this matters**:
- Some servers block requests without User-Agent
- Accept header tells server we support modern formats
- Cache headers ensure fresh content

### ✅ WebP to JPEG Conversion

Automatically detects and converts WebP images:

**Detection Methods**:
1. Content-Type header: `image/webp`
2. File extension: `.webp`
3. Magic bytes: `RIFF...WEBP`
4. Double extensions: `.jpg.webp`

**Conversion**:
- Uses Sharp library for high-quality conversion
- JPEG quality: 90%
- Progressive JPEG encoding
- MozJPEG optimization

---

## API Reference

### `downloadImageWithRetry(imageUrl, outputPath, options)`

Download image with automatic extension detection and retry logic.

**Parameters**:
- `imageUrl` (string): URL of the image to download
- `outputPath` (string): Directory to save the image
- `options` (object, optional):
  - `maxRetries` (number): Maximum retry attempts (default: 3)
  - `retryDelay` (number): Delay between retries in ms (default: 1000)
  - `logger` (object): Logger instance (default: console)

**Returns**: `Promise<Object|null>`
- Success: `{ filename, cleanedUrl }`
- Failure: `null`

**Example**:
```javascript
import { downloadImageWithRetry } from './utilities/imageDownloadService.js';

const result = await downloadImageWithRetry(
    'https://example.com/image',
    '/path/to/output',
    {
        maxRetries: 3,
        logger: myLogger
    }
);

if (result) {
    console.log(`Downloaded: ${result.filename}`);
    console.log(`URL: ${result.cleanedUrl}`);
} else {
    console.log('Download failed after all retries');
}
```

### `batchDownloadImages(imageUrls, outputPath, options)`

Download multiple images with progress tracking.

**Parameters**:
- `imageUrls` (Array<string>): Array of image URLs
- `outputPath` (string): Directory to save images
- `options` (object, optional):
  - `logger` (object): Logger instance
  - `onProgress` (function): Progress callback

**Returns**: `Promise<Object>`
```javascript
{
    successful: [
        { url, filename, cleanedUrl }
    ],
    failed: [
        { url, error }
    ],
    total: number
}
```

**Example**:
```javascript
const urls = [
    'https://example.com/image1',
    'https://example.com/image2',
    'https://example.com/image3'
];

const results = await batchDownloadImages(urls, '/output', {
    logger: myLogger,
    onProgress: (progress) => {
        console.log(`${progress.current}/${progress.total}: ${progress.successful} successful, ${progress.failed} failed`);
    }
});

console.log(`Downloaded ${results.successful.length} images`);
console.log(`Failed ${results.failed.length} images`);
```

### `probeImageExtension(url)`

Probe URL to detect actual extension via HEAD request.

**Parameters**:
- `url` (string): URL to probe

**Returns**: `Promise<string|null>`
- Success: Extension string (e.g., '.jpg')
- Failure: `null`

**Example**:
```javascript
const extension = await probeImageExtension('https://example.com/image');
console.log(extension); // '.jpg'
```

### `isValidImageUrl(url)`

Check if URL is a valid image (filters tracking pixels, etc.).

**Parameters**:
- `url` (string): URL to validate

**Returns**: `boolean`

**Example**:
```javascript
isValidImageUrl('https://example.com/photo.jpg');     // true
isValidImageUrl('https://example.com/tracking.php');  // false
isValidImageUrl('https://example.com/analytics.js');  // false
```

---

## Integration with Crawler

### Updated Image Extraction Flow

**Before** (Old Implementation):
```
1. Extract image URLs from HTML
2. Add .jpg extension if missing
3. Attempt single download
4. Fail on error
```

**After** (New Implementation):
```
1. Extract image URLs from HTML (no extension modification)
2. Pass URLs to downloadImageWithRetry()
3. Service probes for correct extension
4. Service tries multiple extensions with retry logic
5. Service converts WebP if needed
6. Return success/failure with statistics
```

### Code Changes

**File**: `resources/js/crawler/utilities/imageExtraction.js`

**Changed**:
```javascript
// OLD: Premature extension addition
fullUrl = ensureImageExtension(fullUrl);
const result = await downloadImage(fullUrl, imagesDir);

// NEW: Let download service handle extension detection
const result = await downloadImageWithRetry(fullUrl, imagesDir, { logger: log });
```

**Benefits**:
- ✅ No premature assumptions about extension
- ✅ Automatic detection of correct format
- ✅ Better error handling and logging
- ✅ Statistics on success/failure rates

---

## Error Handling

### Error Types

1. **Network Errors**
   - Timeout (30 seconds)
   - Connection refused
   - DNS resolution failure

2. **HTTP Errors**
   - 404 Not Found (expected during probing)
   - 500 Internal Server Error
   - 403 Forbidden

3. **Conversion Errors**
   - WebP conversion failure
   - Invalid image data
   - Insufficient memory

### Error Logging

The service provides detailed error context:

```javascript
logger.error('Failed to download image', {
    url: 'https://example.com/image',
    operation: 'download',
    attempt: 3,
    error: 'HTTP 500'
});
```

**Log Levels**:
- `info`: Successful downloads, probing results
- `warn`: Retry attempts, expected failures (404 during probing)
- `error`: Critical failures after all retries

---

## Performance Considerations

### Optimizations

1. **HEAD Request First**: Lightweight probe before full download
2. **Early Success Return**: Stop trying extensions on first success
3. **Delay Between Attempts**: Prevents server overload (1 second default)
4. **Stream Processing**: Uses axios stream for large files

### Timeouts

- **HEAD Request**: 5 seconds
- **Full Download**: 30 seconds

### Resource Usage

- **Memory**: Buffers entire image in memory during conversion
- **CPU**: Sharp library for WebP conversion (optimized)
- **Network**: One HEAD + up to 6 GET requests per image (worst case)

---

## Testing

### Test Cases

#### 1. URL with Extension
```javascript
// Input: https://example.com/image.jpg
// Expected: Direct download, single request
```

#### 2. URL without Extension (Probing Success)
```javascript
// Input: https://example.com/image
// Expected: HEAD probe → Download with detected extension
```

#### 3. URL without Extension (Probing Fails, Extension Attempts)
```javascript
// Input: https://example.com/image
// Expected: Try .jpg, .jpeg, .png, ... until success
```

#### 4. WebP Image
```javascript
// Input: https://example.com/image.webp
// Expected: Download + Convert to JPEG
```

#### 5. Invalid URL
```javascript
// Input: https://example.com/tracking.php
// Expected: Filtered by isValidImageUrl(), skip download
```

---

## Configuration

### Custom Configuration

You can override defaults by modifying the DOWNLOAD_CONFIG object:

```javascript
// In imageDownloadService.js
const DOWNLOAD_CONFIG = {
    timeout: 60000,                    // Increase to 60 seconds
    maxRetries: 5,                     // Try 5 times
    retryDelay: 2000,                  // 2 seconds between attempts
    commonExtensions: ['.jpg', '.png'], // Only try these
    userAgent: 'MyCustomBot/1.0'       // Custom user agent
};
```

### Per-Request Configuration

Override per request via options:

```javascript
await downloadImageWithRetry(url, output, {
    maxRetries: 5,
    retryDelay: 2000
});
```

---

## Troubleshooting

### Issue: All Images Fail with 403

**Cause**: Server blocking requests without proper User-Agent

**Solution**: Verify User-Agent header is set correctly:
```javascript
'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ...'
```

### Issue: WebP Conversion Fails

**Cause**: Sharp library not installed or corrupted

**Solution**:
```bash
npm install sharp --save
```

### Issue: Timeout on Large Images

**Cause**: 30-second timeout too short

**Solution**: Increase timeout in config or per-request:
```javascript
await downloadImageWithRetry(url, output, {
    timeout: 120000 // 2 minutes
});
```

### Issue: Memory Issues

**Cause**: Buffering large images in memory

**Solution**: Process images in smaller batches or increase Node.js memory:
```bash
node --max-old-space-size=4096 crawler.js
```

---

## Statistics & Monitoring

### Download Summary

The service provides detailed statistics:

```javascript
// From imageExtraction.js downloadAllImages()
log.info(`Image download summary: ${successCount} successful, ${failCount} failed, ${totalDownloaded} total downloaded`);
```

### Individual Download Logs

Each download generates logs:

```
[INFO] Probed extension .jpg for https://example.com/image
[INFO] Attempting download with extension .jpg: https://example.com/image.jpg
[INFO] Downloaded: image.jpg (45632 bytes)
```

### Error Aggregation

Failed downloads are logged with context:

```
[WARN] Failed to download image after all retry attempts: https://example.com/bad-image
[ERROR] Critical error downloading image https://example.com/error: Connection timeout
```

---

## Best Practices

### 1. Always Use Logger

Pass a logger instance for proper tracking:

```javascript
await downloadImageWithRetry(url, output, {
    logger: crawlerLogger
});
```

### 2. Handle Failures Gracefully

Check return value and continue on failure:

```javascript
const result = await downloadImageWithRetry(url, output, { logger });
if (!result) {
    logger.warn(`Skipping image: ${url}`);
    continue; // Continue with next image
}
```

### 3. Batch Processing

Use batch download for better performance:

```javascript
const results = await batchDownloadImages(imageUrls, output, {
    logger,
    onProgress: (p) => console.log(`Progress: ${p.current}/${p.total}`)
});
```

### 4. Filter Invalid URLs

Pre-filter before downloading:

```javascript
const validUrls = imageUrls.filter(isValidImageUrl);
```

---

## Migration Guide

### From Old Implementation

**Step 1**: Update imports
```javascript
// OLD
import { downloadImage } from './imageProcessing.js';

// NEW
import { downloadImageWithRetry } from './imageDownloadService.js';
```

**Step 2**: Remove manual extension addition
```javascript
// OLD
fullUrl = ensureImageExtension(fullUrl);

// NEW
// Just pass the URL as-is
```

**Step 3**: Update function call
```javascript
// OLD
const result = await downloadImage(imageUrl, outputPath);

// NEW
const result = await downloadImageWithRetry(imageUrl, outputPath, {
    logger: log,
    maxRetries: 3
});
```

**Step 4**: Handle new return format
```javascript
// OLD
if (result) {
    images.push(result.cleanedUrl);
}

// NEW (same)
if (result) {
    images.push(result.cleanedUrl);
}
```

---

## Summary

The Image Download Service provides:

✅ **Smart Extension Detection** - Automatic detection via probing and attempts
✅ **Retry Logic** - Configurable retries with delays
✅ **Proper Headers** - User-Agent and Accept headers
✅ **WebP Conversion** - Automatic conversion to JPEG
✅ **Error Handling** - Comprehensive error logging
✅ **Statistics** - Success/failure tracking
✅ **Batch Processing** - Efficient multi-image downloads

**Result**: Reliable image downloading that handles real-world website quirks and server configurations.
