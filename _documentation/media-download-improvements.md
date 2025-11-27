# Media Download Improvements - Complete Guide

## Overview

This document summarizes the comprehensive improvements made to the crawler's media download capabilities. The changes address image download failures, add support for additional document types, implement proper error logging, and provide robust retry logic.

**Date**: 2025-01-18
**Status**: ✅ Complete - Ready for Testing

---

## Problem Statement

### Initial Issues

When testing the crawler with `https://projekte.g.hawk.de/`, the following issues were encountered:

1. **Image Download Failures**
   ```
   Failed to download image https://projekte.g.hawk.de/medien/688b2d07d93fd/gallery/688b388dda2a3.jpg:
   Request failed with status code 500
   ```

2. **Missing File Extensions**
   - URLs like `https://projekte.g.hawk.de/medien/688b2d07d93fd/gallery/688b388dda2a3` were scraped without extensions
   - Server expected `.jpg` or other extension to serve the image
   - Simple addition of `.jpg` didn't work for all image types

3. **Limited File Type Support**
   - Only PDFs were downloadable
   - No support for Word documents (.doc, .docx)
   - No support for Excel spreadsheets (.xls, .xlsx)
   - No support for PowerPoint presentations (.ppt, .pptx)

4. **Poor Error Logging**
   - Basic `console.error()` statements
   - No context or structured logging
   - No error aggregation or statistics
   - Difficult to debug production issues

---

## Solution Overview

### Three New Services

1. **Image Download Service** (`imageDownloadService.js`)
   - Smart extension detection via HEAD requests
   - Retry logic with multiple extension attempts
   - WebP to JPEG conversion
   - Proper HTTP headers

2. **Document Download Service** (`documentDownloadService.js`)
   - Multi-format support (PDF, Word, Excel, PowerPoint)
   - Exponential backoff retry logic
   - Content-type validation
   - Progress tracking
   - File size limits

3. **Logging Service** (`loggingService.js`)
   - Centralized structured logging
   - Multiple log levels (DEBUG, INFO, WARN, ERROR)
   - File and console output
   - Error aggregation and statistics
   - Session tracking
   - Detailed error reports

---

## Changes Made

### 1. Image Download Service

**File**: `resources/js/crawler/utilities/imageDownloadService.js` (NEW)

**Key Features**:

#### Extension Detection Strategy
```
1. Check if URL has extension → Direct download
2. Send HEAD request → Detect content-type → Use detected extension
3. Try common extensions (.jpg, .jpeg, .png, .webp, .gif, .svg)
4. Return null after all attempts fail
```

**Example Flow**:
```javascript
// URL without extension
https://projekte.g.hawk.de/medien/688b2d07d93fd/gallery/688b388dda2a3

// Strategy 1: HEAD request
HEAD request → Content-Type: image/jpeg
Download: https://projekte.g.hawk.de/.../688b388dda2a3.jpg
✓ Success

// Strategy 2: Extension attempts (if HEAD fails)
Try: .jpg → 404
Try: .jpeg → 404
Try: .png → 200 ✓ Success
```

#### Proper HTTP Headers
```javascript
{
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ...',
    'Accept': 'image/webp,image/apng,image/*,*/*;q=0.8',
    'Accept-Language': 'en-US,en;q=0.9',
    'Cache-Control': 'no-cache',
    'Pragma': 'no-cache'
}
```

### 2. Document Download Service

**File**: `resources/js/crawler/utilities/documentDownloadService.js` (NEW)

**Supported Formats**:
- **PDF**: `.pdf`
- **Word**: `.doc`, `.docx`
- **Excel**: `.xls`, `.xlsx`
- **PowerPoint**: `.ppt`, `.pptx`
- **Text**: `.txt`, `.rtf`
- **Data**: `.csv`, `.json`, `.xml`
- **Archives**: `.zip`

**Key Features**:

#### Exponential Backoff
```
Attempt 1: Immediate
Attempt 2: Wait 2 seconds
Attempt 3: Wait 4 seconds
Attempt 4: Wait 8 seconds
```

#### Content-Type Validation
```javascript
// URL: document.docx
// Expected: application/vnd.openxmlformats-officedocument.wordprocessingml.document
// Actual: application/pdf
// Action: Log warning, continue download
```

#### Progress Tracking
```javascript
await downloadDocumentWithRetry(url, output, {
    onProgress: (progress) => {
        console.log(`${progress.downloaded} / ${progress.total} bytes`);
        console.log(`${progress.progress}% complete`);
    }
});
```

### 3. Logging Service

**File**: `resources/js/crawler/utilities/loggingService.js` (NEW)

**Key Features**:

#### Structured Logging
```javascript
logger.error('Download failed', {
    url: 'https://example.com/image.jpg',
    stage: 'execution',
    operation: 'download',
    statusCode: 404,
    retries: 3
});
```

**Log Entry Format**:
```json
{
    "timestamp": "2025-01-18T14:35:22.123Z",
    "session": "1737213322123-abc123",
    "level": "ERROR",
    "label": "crawler",
    "message": "Download failed",
    "url": "https://example.com/image.jpg",
    "stage": "execution",
    "operation": "download",
    "statusCode": 404,
    "retries": 3
}
```

#### Error Aggregation
```javascript
const stats = logger.getErrorStatistics();
console.log(`Errors: ${stats.errorCount}`);
console.log(`Warnings: ${stats.warningCount}`);

// Generate detailed report
const filepath = logger.saveErrorReport();
```

### 4. Updated Image Extraction

**File**: `resources/js/crawler/utilities/imageExtraction.js` (MODIFIED)

**Changes**:
- Removed premature extension addition with `ensureImageExtension()`
- Now passes raw URLs to `downloadImageWithRetry()`
- Service handles extension detection automatically
- Better error handling and statistics logging

**Before**:
```javascript
fullUrl = ensureImageExtension(fullUrl);  // Always adds .jpg
const result = await downloadImage(fullUrl, imagesDir);
```

**After**:
```javascript
// No extension modification
const result = await downloadImageWithRetry(fullUrl, imagesDir, { logger: log });
```

### 5. Updated Crawler Main Logic

**File**: `resources/js/crawler/crawler.js` (MODIFIED)

#### New Function: `downloadDocumentsFromHtml()`

Replaces `downloadPdfsFromHtml()` with multi-format support:

```javascript
async function downloadDocumentsFromHtml($, pageUrl, pageDir, log) {
    // Find all document links
    const documentSelectors = [
        'a[href$=".pdf"]', 'a[href*=".pdf?"]',
        'a[href$=".doc"]', 'a[href*=".doc?"]',
        'a[href$=".docx"]', 'a[href*=".docx?"]',
        'a[href$=".xls"]', 'a[href*=".xls?"]',
        'a[href$=".xlsx"]', 'a[href*=".xlsx?"]',
        // ... more formats
    ];

    // Download each document
    for (const href of documentHrefs) {
        const result = await downloadDocumentWithRetry(absolute, filesDir, {
            logger: log,
            maxRetries: 3
        });

        if (result) {
            saved.push({
                url: absolute,
                local_path: result.path,
                type: result.contentType,
                size: result.size
            });
        }
    }

    return saved;
}
```

#### Updated Function: `maybeHandleDirectDocument()`

Renamed from `maybeHandleDirectPdf()` and enhanced:

```javascript
async function maybeHandleDirectDocument({ request, response, log, metrics }) {
    // Check for any document type (not just PDF)
    const isDocumentUrl = isValidDocumentUrl(request.url);
    const isDocumentContentType = /* check headers */;

    if (!isDocumentUrl && !isDocumentContentType) {
        return { handled: false };
    }

    // Download using enhanced service
    const result = await downloadDocumentWithRetry(request.url, filesDir, {
        logger: log,
        maxRetries: 3
    });

    // Store in appropriate field based on type
    if (result.contentType.includes('pdf')) {
        data.pdfs = [{ url, local_path: result.path, size: result.size }];
    } else {
        data.documents = [{
            url,
            local_path: result.path,
            type: result.contentType,
            size: result.size
        }];
    }

    return { handled: true };
}
```

---

## File Structure Changes

### New Files

```
resources/js/crawler/utilities/
├── imageDownloadService.js          [NEW - 350 lines]
├── documentDownloadService.js       [NEW - 380 lines]
└── loggingService.js                [NEW - 320 lines]

_documentation/
├── image-download-service.md        [NEW - Comprehensive guide]
├── document-download-service.md     [NEW - Comprehensive guide]
├── logging-service.md               [NEW - Comprehensive guide]
└── media-download-improvements.md   [NEW - This file]
```

### Modified Files

```
resources/js/crawler/
├── crawler.js                       [MODIFIED]
│   ├── Import new services
│   ├── Replace downloadPdfsFromHtml() with downloadDocumentsFromHtml()
│   ├── Replace maybeHandleDirectPdf() with maybeHandleDirectDocument()
│   └── Update document handling logic
│
└── utilities/
    └── imageExtraction.js           [MODIFIED]
        ├── Remove ensureImageExtension() calls
        ├── Use downloadImageWithRetry()
        └── Enhanced error logging
```

---

## Testing Checklist

### 1. Image Download Testing

#### Test Case 1: Images with Extensions
```bash
# URL with extension (standard case)
https://example.com/image.jpg

Expected: ✅ Direct download
Actual: ___________
```

#### Test Case 2: Images without Extensions
```bash
# URL without extension (problematic case)
https://projekte.g.hawk.de/medien/688b2d07d93fd/gallery/688b388dda2a3

Expected: ✅ HEAD probe → extension detected → download successful
Actual: ___________
```

#### Test Case 3: WebP Images
```bash
# WebP image that needs conversion
https://example.com/image.webp

Expected: ✅ Download → Convert to JPEG
Actual: ___________
```

#### Test Case 4: Multiple Image Types
```bash
# Page with mixed image types
https://projekte.g.hawk.de/

Expected: ✅ All images downloaded with correct extensions
Actual: ___________
```

### 2. Document Download Testing

#### Test Case 5: PDF Documents
```bash
# PDF link
https://example.com/document.pdf

Expected: ✅ Download successful
Actual: ___________
```

#### Test Case 6: Word Documents
```bash
# Word document link
https://example.com/document.docx

Expected: ✅ Download successful with .docx extension
Actual: ___________
```

#### Test Case 7: Excel Spreadsheets
```bash
# Excel spreadsheet link
https://example.com/data.xlsx

Expected: ✅ Download successful with .xlsx extension
Actual: ___________
```

#### Test Case 8: Mixed Documents on Page
```bash
# Page with multiple document types
https://projekte.g.hawk.de/

Expected: ✅ All documents downloaded (PDF, Word, Excel, etc.)
Actual: ___________
```

### 3. Error Handling Testing

#### Test Case 9: Retry Logic
```bash
# Simulate temporary server error (mock)

Expected: ✅ Retries 3 times with exponential backoff
Actual: ___________
```

#### Test Case 10: Failed Downloads
```bash
# URL that will fail (404)
https://example.com/does-not-exist.jpg

Expected: ✅ Logs error after all retries, continues with next image
Actual: ___________
```

### 4. Logging Testing

#### Test Case 11: Log File Creation
```bash
# Check log directory
ls -la storage/logs/crawler/

Expected: ✅ crawler-YYYY-MM-DD.log file exists
Actual: ___________
```

#### Test Case 12: Error Report Generation
```bash
# After crawl with errors
storage/logs/crawler/error-report-*.json

Expected: ✅ JSON file with error statistics
Actual: ___________
```

### 5. Integration Testing

#### Test Case 13: Full Crawl with Real Site
```bash
php artisan crawlee:scrape "https://projekte.g.hawk.de/" \
    --label=test-crawl \
    --max-pages=10 \
    --store-db

Expected: ✅ Images downloaded successfully
Expected: ✅ Documents downloaded successfully
Expected: ✅ Data stored in database
Expected: ✅ Logs created
Actual: ___________
```

---

## How to Test

### Step 1: Run the Crawler

```bash
php artisan crawlee:scrape "https://projekte.g.hawk.de/" \
    --label=media-test \
    --max-pages=20 \
    --store-db
```

### Step 2: Check Console Output

Look for:
```
✅ [INFO] Probed extension .jpg for https://...
✅ [INFO] Downloaded: image.jpg (45632 bytes)
✅ [INFO] Saved document: report.pdf (1048576 bytes)
✅ [INFO] Image download summary: 15 successful, 2 failed, 15 total downloaded
✅ [INFO] Downloaded 5 documents from page
```

Look for errors:
```
⚠️ [WARN] Retry attempt 2/3 after 2000ms delay
❌ [ERROR] Failed to download image after all retry attempts: https://...
```

### Step 3: Verify Downloads

```bash
# Check crawled directory
ls -R storage/app/private/crawled-data/media-test/

# Should see:
# - 00001/
#   ├── images/
#   │   ├── image1.jpg
#   │   ├── image2.png
#   │   └── ...
#   ├── files/
#   │   ├── document1.pdf
#   │   ├── spreadsheet1.xlsx
#   │   └── ...
#   └── data_00001.json
```

### Step 4: Check Logs

```bash
# View main log
cat storage/logs/crawler/crawler-$(date +%Y-%m-%d).log | jq '.'

# View errors only
cat storage/logs/crawler/crawler-errors-$(date +%Y-%m-%d).log | jq '.'

# Count errors
cat storage/logs/crawler/crawler-$(date +%Y-%m-%d).log | jq 'select(.level == "ERROR")' | wc -l

# Group errors by URL
cat storage/logs/crawler/crawler-$(date +%Y-%m-%d).log | \
    jq -r 'select(.level == "ERROR") | .url' | \
    sort | uniq -c | sort -rn
```

### Step 5: Check Database

```bash
php artisan tinker
```

```php
// Check scraped pages
$pages = \App\Models\ScrapedElement::where('crawler_label', 'media-test')->get();

// Check images
$pages->each(function($page) {
    echo "Page: {$page->title}\n";
    echo "Images: " . count($page->images ?? []) . "\n";
    echo "PDFs: " . count($page->pdfs ?? []) . "\n";
    echo "Documents: " . count($page->documents ?? []) . "\n";
    echo "---\n";
});

// Check for specific URL
$page = \App\Models\ScrapedElement::where('page_url', 'https://projekte.g.hawk.de/specific-page')->first();
print_r($page->images);
print_r($page->documents);
```

---

## Expected Results

### Success Metrics

✅ **Image Downloads**
- Images without extensions are successfully downloaded
- Multiple format support (.jpg, .png, .webp, .gif, .svg)
- WebP images converted to JPEG
- Success rate > 90%

✅ **Document Downloads**
- PDFs downloaded successfully
- Word documents (.docx) downloaded
- Excel spreadsheets (.xlsx) downloaded
- PowerPoint presentations (.pptx) downloaded
- Success rate > 95%

✅ **Error Handling**
- Failed downloads logged with context
- Retry logic working (visible in logs)
- Crawler continues after failures
- No crashes

✅ **Logging**
- Log files created in `storage/logs/crawler/`
- Structured JSON format
- Error reports generated
- Statistics accurate

✅ **Database Storage**
- Scraped pages stored correctly
- Images array populated
- Documents array populated
- PDFs array populated

---

## Troubleshooting

### Issue: Still Getting Image Download Errors

**Check**:
1. Are the new service files present?
   ```bash
   ls resources/js/crawler/utilities/imageDownloadService.js
   ```

2. Are the imports correct in `crawler.js`?
   ```javascript
   import { downloadDocumentWithRetry, isValidDocumentUrl } from './utilities/documentDownloadService.js';
   ```

3. Is the old `downloadImage` function still being called?
   ```bash
   grep -r "downloadImage" resources/js/crawler/utilities/imageExtraction.js
   # Should only see downloadImageWithRetry
   ```

### Issue: No Log Files Created

**Check**:
1. Log directory exists:
   ```bash
   mkdir -p storage/logs/crawler
   chmod 755 storage/logs/crawler
   ```

2. Logger is being used:
   ```bash
   grep -r "createLogger" resources/js/crawler/
   ```

### Issue: Documents Not Downloading

**Check**:
1. Document selectors are correct in `crawler.js`:
   ```bash
   grep "documentSelectors" resources/js/crawler/crawler.js
   ```

2. Function is being called:
   ```bash
   grep "downloadDocumentsFromHtml" resources/js/crawler/crawler.js
   ```

3. Check console for error messages

---

## Performance Considerations

### Resource Usage

**Before**:
- Single attempt per image/document
- No retry logic
- Fast failures

**After**:
- Multiple attempts (HEAD + retries)
- Exponential backoff delays
- More reliable but slightly slower

**Impact**:
- Crawl time may increase by 10-20% due to retries
- But success rate improves significantly
- Acceptable trade-off for reliability

### Optimization Tips

1. **Adjust Retry Settings**:
   ```javascript
   // In service files
   maxRetries: 2,        // Reduce from 3
   retryDelayBase: 1000  // Reduce from 2000
   ```

2. **Increase Concurrency**:
   ```javascript
   await batchDownloadDocuments(urls, output, {
       concurrency: 5  // Increase from 3
   });
   ```

3. **Filter Log Level**:
   ```javascript
   const logger = createLogger({
       logLevel: LOG_LEVELS.WARN  // Only WARN and ERROR
   });
   ```

---

## Migration Summary

### For Existing Crawlers

If you have existing crawler code, here's what to update:

#### 1. Add New Service Imports

```javascript
// In crawler.js (already done)
import { downloadDocumentWithRetry, isValidDocumentUrl } from './utilities/documentDownloadService.js';
```

#### 2. Replace Old Functions

```javascript
// OLD
downloadPdfsFromHtml()

// NEW
downloadDocumentsFromHtml()
```

```javascript
// OLD
maybeHandleDirectPdf()

// NEW
maybeHandleDirectDocument()
```

#### 3. Update Image Extraction

```javascript
// In imageExtraction.js (already done)
// OLD
import { downloadImage } from './imageProcessing.js';
const result = await downloadImage(imageUrl, imagesDir);

// NEW
import { downloadImageWithRetry } from './imageDownloadService.js';
const result = await downloadImageWithRetry(imageUrl, imagesDir, { logger: log });
```

#### 4. Add Logging (Optional but Recommended)

```javascript
import { createLogger } from './utilities/loggingService.js';

const logger = createLogger({ label: 'my-crawler' });

// Use throughout your code
logger.info('Starting crawl');
logger.error('Something failed', { context });
```

---

## What's New - Quick Reference

### New Capabilities

✅ **Images**:
- Smart extension detection
- HEAD request probing
- Multiple extension attempts
- Proper HTTP headers
- WebP conversion
- Retry logic

✅ **Documents**:
- Multi-format support (PDF, Word, Excel, PowerPoint, etc.)
- Exponential backoff
- Content-type validation
- Progress tracking
- File size limits

✅ **Logging**:
- Structured JSON logging
- Multiple log levels
- File and console output
- Error aggregation
- Session tracking
- Detailed reports

✅ **Reliability**:
- Comprehensive error handling
- Automatic retries
- Graceful degradation
- Continue on failures

---

## Summary

This comprehensive update transforms the crawler's media download capabilities from basic to enterprise-grade. The changes provide:

1. **Robustness**: Smart retry logic and extension detection
2. **Completeness**: Support for all common document types
3. **Observability**: Comprehensive logging and error tracking
4. **Reliability**: Graceful error handling and recovery

**Status**: ✅ Implementation complete, ready for testing

**Next Step**: Test with `https://projekte.g.hawk.de/` and verify all improvements work as expected.

---

## Contact & Support

For issues or questions:
1. Check console logs for errors
2. Review log files in `storage/logs/crawler/`
3. Check error reports generated after crawl
4. Review documentation files for specific services

**Documentation Files**:
- `image-download-service.md` - Image download details
- `document-download-service.md` - Document download details
- `logging-service.md` - Logging system details
- `media-download-improvements.md` - This summary document
