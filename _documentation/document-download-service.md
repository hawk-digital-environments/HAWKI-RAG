# Document Download Service Documentation

## Overview

The **Document Download Service** provides robust downloading capabilities for various document types including PDF, Microsoft Word, Excel, PowerPoint, and other file formats. It implements enterprise-grade retry logic, proper HTTP headers, and comprehensive error handling.

**Location**: `resources/js/crawler/utilities/documentDownloadService.js`

---

## Supported Document Types

### Microsoft Office Documents
- **Word**: `.doc`, `.docx`
- **Excel**: `.xls`, `.xlsx`
- **PowerPoint**: `.ppt`, `.pptx`

### PDF Documents
- **PDF**: `.pdf`

### Text Documents
- **Plain Text**: `.txt`
- **Rich Text**: `.rtf`

### Data Files
- **CSV**: `.csv`
- **JSON**: `.json`
- **XML**: `.xml`

### Archives
- **ZIP**: `.zip`

---

## Key Features

### ✅ Retry Logic with Exponential Backoff

Unlike simple retry mechanisms, the service implements **exponential backoff**:

```
Attempt 1: Immediate
Attempt 2: Wait 2 seconds
Attempt 3: Wait 4 seconds
Attempt 4: Wait 8 seconds
```

**Formula**: `delay = baseDelay * 2^(attempt-1)`

**Why Exponential Backoff?**
- Prevents server overload during temporary issues
- Gives servers time to recover
- Industry best practice for reliable downloads

### ✅ Smart Content-Type Validation

The service validates downloaded files against expected types:

```javascript
// URL: document.docx
// Expected: application/vnd.openxmlformats-officedocument.wordprocessingml.document
// Actual: application/octet-stream
// Action: Log warning but proceed
```

**Validation Steps**:
1. Extract expected extension from URL
2. Check content-type header from response
3. Compare and log mismatches
4. Validate file using magic bytes where applicable

### ✅ Proper HTTP Headers

Professional-grade HTTP headers that servers expect:

```javascript
{
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ...',
    'Accept': 'application/pdf,application/msword,...',
    'Accept-Language': 'en-US,en;q=0.9',
    'Cache-Control': 'no-cache',
    'Pragma': 'no-cache'
}
```

**Impact**:
- ✅ Bypasses basic bot detection
- ✅ Signals server about accepted formats
- ✅ Ensures fresh document downloads

### ✅ File Size Limits

**Default Limit**: 100 MB per document

**Rationale**:
- Prevents memory exhaustion
- Reasonable limit for most documents
- Configurable for specific needs

**Enforcement**:
1. Pre-check via `content-length` header
2. Validation after download
3. Graceful error on size exceeded

### ✅ Magic Byte Validation

For critical formats (PDF), validates file integrity:

```javascript
// PDF files must start with %PDF
const buffer = downloadedFile;
const isPdf = buffer.slice(0, 4).toString() === '%PDF';
```

**Supported Validations**:
- PDF: `%PDF` magic bytes
- ZIP: `PK` signature
- Future: Word, Excel OOXML validation

---

## API Reference

### `downloadDocumentWithRetry(documentUrl, outputPath, options)`

Download document with automatic retry logic and exponential backoff.

**Parameters**:
- `documentUrl` (string): URL of the document
- `outputPath` (string): Directory to save the document
- `options` (object, optional):
  - `maxRetries` (number): Maximum retry attempts (default: 3)
  - `retryDelayBase` (number): Base delay in ms (default: 2000)
  - `logger` (object): Logger instance (default: console)
  - `onProgress` (function): Progress callback for download

**Returns**: `Promise<Object|null>`

**Success Object**:
```javascript
{
    filename: 'document.pdf',
    cleanedUrl: 'https://example.com/document.pdf',
    size: 1048576,                    // bytes
    contentType: 'application/pdf',
    path: '/output/document.pdf'
}
```

**Example**:
```javascript
import { downloadDocumentWithRetry } from './utilities/documentDownloadService.js';

const result = await downloadDocumentWithRetry(
    'https://example.com/report.pdf',
    '/path/to/output',
    {
        maxRetries: 3,
        logger: myLogger,
        onProgress: (progress) => {
            console.log(`Downloaded: ${progress.progress.toFixed(2)}%`);
        }
    }
);

if (result) {
    console.log(`Saved: ${result.filename} (${result.size} bytes)`);
} else {
    console.log('Download failed after all retries');
}
```

### `batchDownloadDocuments(documentUrls, outputPath, options)`

Download multiple documents with concurrent processing.

**Parameters**:
- `documentUrls` (Array<string>): Array of document URLs
- `outputPath` (string): Directory to save documents
- `options` (object, optional):
  - `logger` (object): Logger instance
  - `onProgress` (function): Progress callback
  - `concurrency` (number): Concurrent downloads (default: 3)

**Returns**: `Promise<Object>`

**Result Object**:
```javascript
{
    successful: [
        {
            url: 'https://example.com/doc1.pdf',
            filename: 'doc1.pdf',
            size: 1048576,
            contentType: 'application/pdf',
            path: '/output/doc1.pdf'
        }
    ],
    failed: [
        {
            url: 'https://example.com/doc2.pdf',
            error: 'HTTP 404'
        }
    ],
    total: 2,
    totalSize: 1048576    // Total bytes downloaded
}
```

**Example**:
```javascript
const urls = [
    'https://example.com/report.pdf',
    'https://example.com/data.xlsx',
    'https://example.com/presentation.pptx'
];

const results = await batchDownloadDocuments(urls, '/output', {
    logger: myLogger,
    concurrency: 3,
    onProgress: (progress) => {
        console.log(`Progress: ${progress.current}/${progress.total}`);
        console.log(`Current file: ${progress.progress.toFixed(2)}%`);
    }
});

console.log(`Success: ${results.successful.length}`);
console.log(`Failed: ${results.failed.length}`);
console.log(`Total size: ${formatBytes(results.totalSize)}`);
```

### `isValidDocumentUrl(url)`

Check if URL points to a valid document.

**Parameters**:
- `url` (string): URL to validate

**Returns**: `boolean`

**Filters Out**:
- Tracking scripts (analytics.js)
- PHP scripts with params (.php?)
- CSS/JS files
- Data URIs

**Example**:
```javascript
isValidDocumentUrl('https://example.com/report.pdf');      // true
isValidDocumentUrl('https://example.com/data.xlsx');       // true
isValidDocumentUrl('https://example.com/tracking.php');    // false
isValidDocumentUrl('https://example.com/style.css');       // false
```

---

## Configuration

### Default Configuration

```javascript
const DOWNLOAD_CONFIG = {
    timeout: 60000,              // 60 seconds (documents can be large)
    maxRetries: 3,               // 3 retry attempts
    retryDelayBase: 2000,        // 2 seconds base delay (exponential backoff)
    userAgent: 'Mozilla/5.0 ...',
    maxFileSize: 100 * 1024 * 1024  // 100 MB
};
```

### Content-Type Mappings

```javascript
const DOCUMENT_TYPES = {
    // PDF
    'application/pdf': '.pdf',

    // Microsoft Word
    'application/msword': '.doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': '.docx',

    // Microsoft Excel
    'application/vnd.ms-excel': '.xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': '.xlsx',

    // Microsoft PowerPoint
    'application/vnd.ms-powerpoint': '.ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation': '.pptx',

    // ... and more
};
```

---

## Integration with Crawler

### Enhanced Download Flow

**Before** (Old PDF-Only Implementation):
```
1. Find <a href="*.pdf"> links
2. Basic fetch with fetch()
3. Single attempt, fail on error
4. Save as PDF only
```

**After** (New Multi-Format Implementation):
```
1. Find links to all document types
   - PDF, Word, Excel, PowerPoint, etc.
2. Use documentDownloadService
3. Retry with exponential backoff
4. Content-type validation
5. Progress tracking
6. Comprehensive error logging
7. Save with metadata
```

### Code Changes in crawler.js

**File**: `resources/js/crawler/crawler.js`

**New Function**: `downloadDocumentsFromHtml()`

```javascript
/**
 * Download all document links found on an HTML page
 */
async function downloadDocumentsFromHtml($, pageUrl, pageDir, log) {
    const documentSelectors = [
        'a[href$=".pdf"]', 'a[href*=".pdf?"]',
        'a[href$=".doc"]', 'a[href*=".doc?"]',
        'a[href$=".docx"]', 'a[href*=".docx?"]',
        'a[href$=".xls"]', 'a[href*=".xls?"]',
        'a[href$=".xlsx"]', 'a[href*=".xlsx?"]',
        'a[href$=".ppt"]', 'a[href*=".ppt?"]',
        'a[href$=".pptx"]', 'a[href*=".pptx?"]',
        // ... more formats
    ];

    // Find all document links
    const documentHrefs = new Set(
        $(documentSelectors.join(', '))
            .map((_, a) => $(a).attr('href'))
            .get()
            .filter(Boolean)
            .filter(href => isValidDocumentUrl(href))
    );

    // Download each document
    for (const href of documentHrefs) {
        const absolute = new URL(href, pageUrl).href;
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

**New Function**: `maybeHandleDirectDocument()`

Handles direct document downloads (when URL itself is a document):

```javascript
async function maybeHandleDirectDocument({ request, response, log, metrics }) {
    // Check if request is for a document
    const isDocumentUrl = isValidDocumentUrl(request.url);
    const isDocumentContentType = /* check content-type */;

    if (!isDocumentUrl && !isDocumentContentType) {
        return { handled: false };
    }

    // Download using service
    const result = await downloadDocumentWithRetry(request.url, filesDir, {
        logger: log,
        maxRetries: 3
    });

    // Save metadata
    const data = assemblePageData(titleFromFilename, url, null, [], null);

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

    savePageData(pageDir, formattedId, data, titleFromFilename, log);
    return { handled: true };
}
```

---

## Error Handling

### Retry Strategy

**Retry Conditions**:
- ✅ Timeout errors
- ✅ Network errors (ECONNRESET, etc.)
- ✅ Server errors (5xx)
- ✅ Rate limiting (429)
- ❌ Client errors (4xx, except 408 and 429)

**Smart Retry Logic**:
```javascript
// Don't retry on permanent client errors
if (error.response?.status >= 400 && error.response?.status < 500) {
    if (![408, 429].includes(error.response.status)) {
        logger.error(`Client error ${error.response.status}, aborting retries`);
        break;
    }
}
```

### Error Types

1. **Network Errors**
   ```
   ETIMEDOUT: Connection timeout
   ECONNRESET: Connection reset by peer
   ENOTFOUND: DNS resolution failed
   ```

2. **HTTP Errors**
   ```
   404: Document not found
   403: Access forbidden
   429: Rate limit exceeded
   500-599: Server errors
   ```

3. **Validation Errors**
   ```
   Empty file: Downloaded 0 bytes
   Size exceeded: File > 100 MB
   Type mismatch: content-type doesn't match extension
   ```

### Error Logging

Comprehensive error context:

```javascript
logger.error('Failed to download document after 3 attempts', {
    url: 'https://example.com/doc.pdf',
    operation: 'download',
    attempts: 3,
    lastError: 'HTTP 500',
    size: 0
});
```

---

## Progress Tracking

### Per-Document Progress

Track download progress for large files:

```javascript
await downloadDocumentWithRetry(url, output, {
    onProgress: (progress) => {
        console.log(`Downloaded: ${progress.downloaded} / ${progress.total} bytes`);
        console.log(`Progress: ${progress.progress.toFixed(2)}%`);
    }
});
```

**Progress Object**:
```javascript
{
    downloaded: 524288,    // bytes downloaded so far
    total: 1048576,        // total file size
    progress: 50.0         // percentage (0-100)
}
```

### Batch Progress

Track overall batch progress:

```javascript
await batchDownloadDocuments(urls, output, {
    onProgress: (progress) => {
        console.log(`File ${progress.current}/${progress.total}`);
        console.log(`Current: ${progress.url}`);
        console.log(`Progress: ${progress.progress.toFixed(2)}%`);
    }
});
```

**Batch Progress Object**:
```javascript
{
    current: 2,                        // current file number
    total: 5,                          // total files
    url: 'https://example.com/doc2',   // current URL
    downloaded: 262144,                // current file bytes
    total: 524288,                     // current file size
    progress: 50.0                     // current file progress %
}
```

---

## Performance Optimization

### Concurrent Downloads

**Default Concurrency**: 3 simultaneous downloads

**Why 3?**
- Balances speed vs. server load
- Prevents connection pool exhaustion
- Reasonable for most servers

**Customization**:
```javascript
await batchDownloadDocuments(urls, output, {
    concurrency: 5  // Download 5 at a time
});
```

**Considerations**:
- Higher concurrency = faster but more load
- Server rate limiting may kick in
- Respect robots.txt and server policies

### Memory Management

**Stream Processing**:
- Uses axios `responseType: 'stream'`
- Processes in chunks
- Doesn't load entire file first

**Buffer Handling**:
```javascript
const chunks = [];
for await (const chunk of response.data) {
    chunks.push(chunk);
}
const buffer = Buffer.concat(chunks);
```

**Memory Safety**:
- 100 MB file size limit
- Chunks prevent memory spikes
- Validation before writing

---

## Testing

### Test Cases

#### 1. PDF Download
```javascript
const result = await downloadDocumentWithRetry(
    'https://example.com/report.pdf',
    '/output'
);
// Expected: Success with PDF metadata
```

#### 2. Word Document
```javascript
const result = await downloadDocumentWithRetry(
    'https://example.com/document.docx',
    '/output'
);
// Expected: Success with .docx extension
```

#### 3. Excel Spreadsheet
```javascript
const result = await downloadDocumentWithRetry(
    'https://example.com/data.xlsx',
    '/output'
);
// Expected: Success with .xlsx extension
```

#### 4. Retry on Temporary Error
```javascript
// First attempt: 500 error
// Second attempt: Success
// Expected: Success after 1 retry
```

#### 5. File Size Exceeded
```javascript
// File: 150 MB
// Limit: 100 MB
// Expected: Error with size exceeded message
```

---

## Statistics & Monitoring

### Batch Statistics

```javascript
const results = await batchDownloadDocuments(urls, output, { logger });

console.log(`Total documents: ${results.total}`);
console.log(`Successful: ${results.successful.length}`);
console.log(`Failed: ${results.failed.length}`);
console.log(`Total size: ${formatBytes(results.totalSize)}`);
console.log(`Success rate: ${(results.successful.length / results.total * 100).toFixed(2)}%`);
```

### Individual Download Logs

```
[INFO] Starting download: https://example.com/report.pdf
[INFO] Downloaded: report.pdf (1048576 bytes, content-type: application/pdf)
```

```
[WARN] Download attempt 1/3 failed: HTTP 500
[INFO] Retry attempt 1/3 after 2000ms delay
[INFO] Downloaded: report.pdf (1048576 bytes, content-type: application/pdf)
```

---

## Troubleshooting

### Issue: Downloads Timeout

**Symptoms**: All downloads fail with timeout error

**Cause**: 60-second timeout too short for large files or slow connections

**Solution**: Increase timeout
```javascript
// In documentDownloadService.js
const DOWNLOAD_CONFIG = {
    timeout: 120000  // 2 minutes
};
```

### Issue: Rate Limiting (429 Errors)

**Symptoms**: Many 429 responses

**Cause**: Too many concurrent requests

**Solution**: Reduce concurrency and add delays
```javascript
await batchDownloadDocuments(urls, output, {
    concurrency: 1,  // Download one at a time
    retryDelayBase: 5000  // 5 seconds between retries
});
```

### Issue: Content-Type Mismatches

**Symptoms**: Warnings about content-type mismatches

**Example**:
```
[WARN] Content-type mismatch: expected .docx, got application/octet-stream
```

**Cause**: Server sends generic content-type

**Action**: Warning only, download proceeds. Validate file manually if needed.

### Issue: Memory Errors

**Symptoms**: `JavaScript heap out of memory`

**Cause**: Downloading many large files simultaneously

**Solution**:
1. Reduce concurrency
2. Increase Node.js memory:
   ```bash
   node --max-old-space-size=4096 crawler.js
   ```
3. Lower file size limit:
   ```javascript
   maxFileSize: 50 * 1024 * 1024  // 50 MB instead of 100 MB
   ```

---

## Best Practices

### 1. Always Use Logger

```javascript
await downloadDocumentWithRetry(url, output, {
    logger: crawlerLogger
});
```

### 2. Handle Failures Gracefully

```javascript
const result = await downloadDocumentWithRetry(url, output, { logger });
if (!result) {
    logger.warn(`Skipping failed document: ${url}`);
    continue;  // Continue with next document
}
```

### 3. Validate Results

```javascript
if (result) {
    // Check file actually exists
    if (fs.existsSync(result.path)) {
        logger.info(`Verified: ${result.filename}`);
    }
}
```

### 4. Monitor Statistics

```javascript
const results = await batchDownloadDocuments(urls, output, { logger });

// Alert if success rate is low
if (results.successful.length / results.total < 0.8) {
    logger.error(`Low success rate: ${results.successful.length}/${results.total}`);
}
```

---

## Summary

The Document Download Service provides:

✅ **Multi-Format Support** - PDF, Word, Excel, PowerPoint, and more
✅ **Smart Retry Logic** - Exponential backoff with configurable attempts
✅ **Content Validation** - Magic byte checking and type verification
✅ **Progress Tracking** - Real-time download progress
✅ **Concurrent Downloads** - Configurable batch processing
✅ **Size Limits** - Prevents memory exhaustion
✅ **Comprehensive Logging** - Detailed error context
✅ **Enterprise-Grade** - Proper headers, timeouts, error handling

**Result**: Reliable document downloading for production crawlers handling diverse file types and server configurations.
