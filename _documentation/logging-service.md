# Logging Service Documentation

## Overview

The **Crawler Logging Service** provides a centralized, structured logging system for the entire crawler operation. It replaces scattered `console.log` statements with a comprehensive logging infrastructure that supports multiple log levels, file output, error aggregation, and detailed reporting.

**Location**: `resources/js/crawler/utilities/loggingService.js`

---

## Why a Logging Service?

### Problems with Basic Logging

**Before** (console.log everywhere):
```javascript
console.log('Starting download...');
console.error('Download failed!');
console.warn('Retrying...');
```

**Issues**:
- ❌ No timestamps
- ❌ No context (which URL? which stage?)
- ❌ No log levels filtering
- ❌ No file persistence
- ❌ No error aggregation
- ❌ No structured format
- ❌ Difficult to debug production issues

### Solution: Centralized Logging

**After** (CrawlerLogger):
```javascript
logger.error('Download failed', {
    url: 'https://example.com/image.jpg',
    stage: 'image-download',
    operation: 'download',
    attempt: 3
});
```

**Benefits**:
- ✅ Automatic timestamps
- ✅ Rich context
- ✅ Filterable log levels
- ✅ File and console output
- ✅ Error tracking and statistics
- ✅ Structured JSON format
- ✅ Session tracking
- ✅ Error reports

---

## Key Features

### ✅ Multiple Log Levels

```javascript
const LOG_LEVELS = {
    DEBUG: 0,    // Detailed debugging information
    INFO: 1,     // General information
    WARN: 2,     // Warning messages
    ERROR: 3     // Error messages
};
```

**Usage**:
```javascript
logger.debug('Probing image extension', { url });
logger.info('Download successful', { filename, size });
logger.warn('Retry attempt 2/3', { url });
logger.error('Download failed after all retries', { url, error });
```

**Filtering**:
```javascript
// Only show WARN and ERROR
const logger = createLogger({ logLevel: LOG_LEVELS.WARN });

logger.debug('This will not be shown');
logger.info('This will not be shown');
logger.warn('This will be shown');
logger.error('This will be shown');
```

### ✅ Dual Output: Console + File

**Console Output** (colored, formatted):
```
[14:35:22] [crawler] [INFO] Download successful
  URL: https://example.com/image.jpg
  Size: 45632 bytes
```

**File Output** (JSON, structured):
```json
{
  "timestamp": "2025-01-18T14:35:22.123Z",
  "session": "1737213322123-abc123",
  "level": "INFO",
  "label": "crawler",
  "message": "Download successful",
  "url": "https://example.com/image.jpg",
  "size": 45632
}
```

### ✅ Contextual Logging

Add rich context to every log entry:

```javascript
logger.error('Conversion failed', {
    url: 'https://example.com/image.webp',
    stage: 'image-processing',
    operation: 'webp-conversion',
    details: {
        inputFormat: 'webp',
        targetFormat: 'jpeg',
        fileSize: 125648
    },
    stack: error.stack
});
```

**Context Fields**:
- `url`: Related URL
- `stage`: Pipeline stage (validation, execution, etc.)
- `operation`: Specific operation (download, conversion, etc.)
- `details`: Additional structured data
- `stack`: Stack trace for errors

### ✅ Session Tracking

Each logger instance has a unique session ID:

```javascript
const logger = createLogger({ label: 'my-crawl' });
// session: 1737213322123-abc123

logger.info('Started crawling');
// All logs tagged with this session ID
```

**Benefits**:
- Track logs across a single crawl session
- Correlate errors with specific runs
- Debug issues by session
- Aggregate statistics per session

### ✅ Error Aggregation

Automatically tracks errors and warnings:

```javascript
logger.error('Download failed', { url: 'url1' });
logger.error('Download failed', { url: 'url2' });
logger.warn('Retry attempt', { url: 'url3' });

const stats = logger.getErrorStatistics();
// {
//     errorCount: 2,
//     warningCount: 1,
//     errors: [ /* error objects */ ],
//     warnings: [ /* warning objects */ ]
// }
```

---

## API Reference

### `createLogger(options)`

Create a new logger instance.

**Parameters**:
- `options` (object, optional):
  - `label` (string): Logger label (default: 'crawler')
  - `logLevel` (number): Minimum log level (default: LOG_LEVELS.INFO)
  - `logDir` (string): Log file directory (default: 'storage/logs/crawler')
  - `enableConsole` (boolean): Enable console output (default: true)
  - `enableFile` (boolean): Enable file output (default: true)

**Returns**: `CrawlerLogger` instance

**Example**:
```javascript
import { createLogger, LOG_LEVELS } from './utilities/loggingService.js';

const logger = createLogger({
    label: 'image-crawler',
    logLevel: LOG_LEVELS.DEBUG,
    logDir: 'storage/logs/crawler',
    enableConsole: true,
    enableFile: true
});
```

### Logger Methods

#### `debug(message, context = {})`

Log debug-level message.

```javascript
logger.debug('Checking image extension', {
    url: 'https://example.com/image',
    currentExtension: null
});
```

#### `info(message, context = {})`

Log info-level message.

```javascript
logger.info('Download successful', {
    filename: 'image.jpg',
    size: 45632
});
```

#### `warn(message, context = {})`

Log warning-level message.

```javascript
logger.warn('Retry attempt 2/3', {
    url: 'https://example.com/image.jpg',
    reason: 'HTTP 500'
});
```

#### `error(message, context = {})`

Log error-level message. Accepts Error objects.

```javascript
// With Error object
try {
    // ...
} catch (error) {
    logger.error(error, {
        url: 'https://example.com/image.jpg',
        operation: 'download'
    });
}

// With message
logger.error('Download failed', {
    url: 'https://example.com/image.jpg',
    statusCode: 404
});
```

### Specialized Logging Methods

#### `logUrl(level, message, url, context = {})`

Log with URL context.

```javascript
logger.logUrl(LOG_LEVELS.INFO, 'Processing page', 'https://example.com', {
    stage: 'extraction'
});
```

#### `logDownload(success, url, details = {})`

Log download operation result.

```javascript
logger.logDownload(true, 'https://example.com/image.jpg', {
    filename: 'image.jpg',
    size: 45632
});

logger.logDownload(false, 'https://example.com/failed.jpg', {
    error: 'HTTP 404'
});
```

#### `logRetry(attempt, maxAttempts, url, reason = '')`

Log retry attempt.

```javascript
logger.logRetry(2, 3, 'https://example.com/image.jpg', 'HTTP 500');
```

#### `logStage(stage, message, context = {})`

Log pipeline stage change.

```javascript
logger.logStage('validation', 'Validating crawler configuration', {
    maxPages: 100,
    label: 'my-crawl'
});
```

### Statistics & Reporting

#### `getErrorStatistics()`

Get error and warning statistics.

**Returns**:
```javascript
{
    sessionId: '1737213322123-abc123',
    startTime: Date,
    uptime: 125648,              // milliseconds
    errorCount: 5,
    warningCount: 12,
    errors: [ /* error objects */ ],
    warnings: [ /* warning objects */ ]
}
```

**Example**:
```javascript
const stats = logger.getErrorStatistics();
console.log(`Errors: ${stats.errorCount}`);
console.log(`Warnings: ${stats.warningCount}`);
```

#### `generateErrorReport()`

Generate structured error report.

**Returns**: Detailed report object

**Example**:
```javascript
const report = logger.generateErrorReport();
console.log(JSON.stringify(report, null, 2));
```

**Report Structure**:
```javascript
{
    session: {
        id: '1737213322123-abc123',
        startTime: '2025-01-18T14:35:22.123Z',
        uptime: '125s'
    },
    summary: {
        totalErrors: 5,
        totalWarnings: 12
    },
    errors: [
        {
            timestamp: '2025-01-18T14:36:10.456Z',
            message: 'Download failed',
            url: 'https://example.com/image.jpg',
            stage: 'execution',
            operation: 'download',
            details: { statusCode: 404 }
        }
    ],
    warnings: [ /* ... */ ]
}
```

#### `saveErrorReport()`

Save error report to JSON file.

**Returns**: Filepath or null

**Example**:
```javascript
const filepath = logger.saveErrorReport();
console.log(`Report saved: ${filepath}`);
// Output: Report saved: storage/logs/crawler/error-report-crawler-2025-01-18T14-35-22-123Z.json
```

#### `printSummary()`

Print session summary to console.

**Example**:
```javascript
logger.printSummary();
```

**Output**:
```
========================================
CRAWLER SESSION SUMMARY
========================================
Session ID: 1737213322123-abc123
Start Time: 2025-01-18T14:35:22.123Z
Uptime: 125s
Errors: 5
Warnings: 12
========================================

Recent Errors:
  1. [2025-01-18T14:36:10.456Z] Download failed
     URL: https://example.com/image1.jpg
  2. [2025-01-18T14:36:15.789Z] Download failed
     URL: https://example.com/image2.jpg
  ...
```

---

## File Organization

### Log Files

**Directory**: `storage/logs/crawler/`

**Files Created**:

1. **Daily Log File**:
   ```
   crawler-2025-01-18.log
   ```
   Contains all log entries for the day

2. **Daily Error Log**:
   ```
   crawler-errors-2025-01-18.log
   ```
   Contains only ERROR-level entries

3. **Error Reports**:
   ```
   error-report-crawler-2025-01-18T14-35-22-123Z.json
   ```
   Detailed error reports with statistics

### Log Format

**File Format**: Newline-delimited JSON (NDJSON)

**Each line**:
```json
{"timestamp":"2025-01-18T14:35:22.123Z","session":"...","level":"INFO","label":"crawler","message":"...","url":"..."}
```

**Benefits**:
- Easy to parse
- Streamable
- Tools friendly (jq, grep, etc.)

---

## Usage Patterns

### Basic Usage

```javascript
import { createLogger } from './utilities/loggingService.js';

const logger = createLogger({ label: 'my-crawler' });

logger.info('Crawler started');
logger.info('Processing page', { url: 'https://example.com' });
logger.warn('Slow response', { url: 'https://example.com', responseTime: 5000 });
logger.error('Failed to download', { url: 'https://example.com/image.jpg' });

logger.printSummary();
logger.saveErrorReport();
```

### Integration with Download Services

```javascript
import { createLogger } from './utilities/loggingService.js';
import { downloadImageWithRetry } from './utilities/imageDownloadService.js';

const logger = createLogger({ label: 'image-downloader' });

const result = await downloadImageWithRetry(imageUrl, outputPath, {
    logger: logger  // Pass logger to download service
});

if (!result) {
    logger.error('All download attempts failed', { url: imageUrl });
}
```

### Integration with Crawler Pipeline

```javascript
import { createLogger } from './utilities/loggingService.js';

const logger = createLogger({ label: config.label });

// Validation stage
logger.logStage('validation', 'Validating configuration');
const valid = validateConfig(config);
if (!valid) {
    logger.error('Invalid configuration', { config });
}

// Execution stage
logger.logStage('execution', 'Starting crawler');
await runCrawler(config, logger);

// Finalization
logger.logStage('finalization', 'Crawler completed');
logger.printSummary();
logger.saveErrorReport();
```

---

## Console Output Formatting

### Color Coding

- **DEBUG**: Cyan
- **INFO**: Green
- **WARN**: Yellow
- **ERROR**: Red

### Format

```
[TIME] [LABEL] [LEVEL] Message
  Context Field 1: value
  Context Field 2: value
```

**Example**:
```
[14:35:22] [crawler] [ERROR] Download failed
  URL: https://example.com/image.jpg
  Stage: execution
  Operation: download
  Details: {"statusCode":404}
```

---

## Error Tracking

### Automatic Error Collection

Every `logger.error()` call:
1. Increments `errorCount`
2. Adds entry to `errors` array
3. Writes to console (if enabled)
4. Writes to daily log file (if enabled)
5. Writes to daily error log file (if enabled)

### Error Object Structure

```javascript
{
    timestamp: '2025-01-18T14:36:10.456Z',
    session: '1737213322123-abc123',
    level: 'ERROR',
    label: 'crawler',
    message: 'Download failed',
    url: 'https://example.com/image.jpg',
    stage: 'execution',
    operation: 'download',
    details: { statusCode: 404 },
    stack: 'Error: Download failed\n    at ...'
}
```

### Query Errors

```javascript
const stats = logger.getErrorStatistics();

// Get all errors
stats.errors.forEach(error => {
    console.log(`${error.timestamp}: ${error.message}`);
});

// Filter by URL
const imageErrors = stats.errors.filter(e =>
    e.url && e.url.includes('/images/')
);

// Filter by stage
const executionErrors = stats.errors.filter(e =>
    e.stage === 'execution'
);

// Filter by operation
const downloadErrors = stats.errors.filter(e =>
    e.operation === 'download'
);
```

---

## Best Practices

### 1. Create Logger Early

```javascript
// At the start of your application
const logger = createLogger({ label: config.label });
```

### 2. Pass Logger to Services

```javascript
// Don't create new loggers in services
// Pass the main logger instance

await downloadImage(url, output, { logger });
await processData(data, { logger });
```

### 3. Use Appropriate Log Levels

```javascript
logger.debug('Detailed info for debugging');     // DEBUG
logger.info('Normal operation progress');         // INFO
logger.warn('Something unexpected but handled');  // WARN
logger.error('Something failed');                 // ERROR
```

### 4. Provide Context

```javascript
// ❌ Bad: No context
logger.error('Download failed');

// ✅ Good: Rich context
logger.error('Download failed', {
    url: 'https://example.com/image.jpg',
    stage: 'execution',
    operation: 'download',
    statusCode: 404,
    retries: 3
});
```

### 5. Generate Reports

```javascript
// At the end of crawler run
logger.printSummary();
logger.saveErrorReport();
```

---

## Configuration Examples

### Production: Errors Only

```javascript
const logger = createLogger({
    label: 'prod-crawler',
    logLevel: LOG_LEVELS.ERROR,
    enableConsole: false,
    enableFile: true
});
```

### Development: All Logs

```javascript
const logger = createLogger({
    label: 'dev-crawler',
    logLevel: LOG_LEVELS.DEBUG,
    enableConsole: true,
    enableFile: true
});
```

### Testing: Console Only

```javascript
const logger = createLogger({
    label: 'test-crawler',
    logLevel: LOG_LEVELS.INFO,
    enableConsole: true,
    enableFile: false
});
```

---

## Troubleshooting

### Issue: No Log Files Created

**Cause**: Log directory doesn't exist or no write permissions

**Solution**:
```bash
mkdir -p storage/logs/crawler
chmod 755 storage/logs/crawler
```

### Issue: Too Many Log Files

**Cause**: Daily log files accumulate

**Solution**: Implement log rotation
```bash
# Delete logs older than 30 days
find storage/logs/crawler -name "*.log" -mtime +30 -delete
```

### Issue: Large Log Files

**Cause**: DEBUG level with verbose logging

**Solution**:
1. Use INFO level in production
2. Implement log file size limits
3. Use log rotation

### Issue: Performance Impact

**Cause**: Excessive logging with file I/O

**Solution**:
1. Increase log level (WARN or ERROR only)
2. Disable file logging for high-throughput scenarios
3. Buffer writes (not currently implemented)

---

## Advanced Usage

### Custom Log Format

Extend CrawlerLogger for custom formatting:

```javascript
class MyLogger extends CrawlerLogger {
    _formatConsoleOutput(entry) {
        // Custom format
        return `${entry.timestamp} - ${entry.level} - ${entry.message}`;
    }
}
```

### Log Analysis

Parse log files with jq:

```bash
# Count errors by URL
cat storage/logs/crawler/crawler-2025-01-18.log | \
    jq -r 'select(.level == "ERROR") | .url' | \
    sort | uniq -c | sort -rn

# Get all errors from specific stage
cat storage/logs/crawler/crawler-2025-01-18.log | \
    jq 'select(.level == "ERROR" and .stage == "execution")'
```

### Integrate with Monitoring

Send errors to external monitoring:

```javascript
class MonitoredLogger extends CrawlerLogger {
    error(message, context = {}) {
        super.error(message, context);

        // Send to monitoring service
        sendToMonitoring({
            level: 'error',
            message,
            context,
            timestamp: new Date()
        });
    }
}
```

---

## Summary

The Crawler Logging Service provides:

✅ **Structured Logging** - JSON format with rich context
✅ **Multiple Log Levels** - DEBUG, INFO, WARN, ERROR
✅ **Dual Output** - Console (colored) + Files (JSON)
✅ **Session Tracking** - Unique session IDs
✅ **Error Aggregation** - Automatic error/warning collection
✅ **Statistics** - Error counts and summaries
✅ **Error Reports** - Detailed JSON reports
✅ **Contextual** - URL, stage, operation tracking
✅ **Flexible** - Configurable levels, outputs, formats

**Result**: Professional-grade logging for production crawlers with comprehensive debugging and monitoring capabilities.
