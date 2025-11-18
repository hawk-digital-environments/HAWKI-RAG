# Migration Fix: URL Indexing Issue

## Issue

**Error**:
```
SQLSTATE[42000]: Syntax error or access violation: 1170
BLOB/TEXT column 'page_url' used in key specification without a key length
```

## Problem

MySQL cannot create an index on TEXT columns without specifying a key length. The original migration tried to index `page_url` which was defined as `text()`.

## Solution

### 1. Changed URL Storage Strategy

**Before**:
```php
$table->text('page_url')->index(); // ❌ Cannot index TEXT without length
```

**After**:
```php
$table->string('page_url', 2048); // VARCHAR(2048) - can be indexed
$table->string('page_url_hash', 64)->unique(); // SHA256 hash for fast lookups
```

### 2. Added Automatic Hash Generation

**File**: `app/Models/ScrapedPage.php`

```php
protected static function boot()
{
    parent::boot();

    // Automatically generate URL hash before saving
    static::saving(function ($page) {
        if ($page->isDirty('page_url') || empty($page->page_url_hash)) {
            $page->page_url_hash = hash('sha256', $page->page_url);
        }
    });
}
```

### 3. Updated Storage Service to Use Hash

**File**: `app/Services/Crawler/Storage/CrawlerStorageService.php`

```php
// Generate URL hash for lookups
$urlHash = hash('sha256', $pageUrl);

// Check if page already exists (use hash for performance)
$page = ScrapedPage::where('page_url_hash', $urlHash)->first();
```

## Benefits of This Approach

✅ **Performance**: Hash lookups are faster than string comparisons on long URLs
✅ **Uniqueness**: Hash ensures no duplicate URLs (unique constraint)
✅ **Index Support**: VARCHAR can be indexed without length restrictions
✅ **Automatic**: Hash generation is automatic via model events
✅ **Long URL Support**: Supports URLs up to 2048 characters

## Migration Status

✅ **Completed Successfully**

```bash
php artisan migrate:fresh
# Result: All migrations ran successfully including scraped_pages table
```

## Usage

The hash is handled automatically. Just use the model normally:

```php
// The hash will be generated automatically
$page = new ScrapedPage();
$page->page_url = 'https://example.com/very/long/url';
$page->save(); // page_url_hash is auto-generated

// Finding by URL (uses hash internally for performance)
$page = ScrapedPage::where('page_url_hash', hash('sha256', $url))->first();
```

## Database Schema (Updated)

```sql
page_url VARCHAR(2048) NOT NULL          -- The actual URL
page_url_hash VARCHAR(64) UNIQUE NOT NULL -- SHA256 hash (auto-generated)
```

## Files Modified

1. ✅ `database/migrations/2025_11_18_115605_create_scraped_pages_table.php`
   - Changed `page_url` from TEXT to VARCHAR(2048)
   - Added `page_url_hash` column with unique constraint

2. ✅ `app/Models/ScrapedPage.php`
   - Added `page_url_hash` to fillable
   - Added boot method for automatic hash generation

3. ✅ `app/Services/Crawler/Storage/CrawlerStorageService.php`
   - Updated lookup to use hash instead of URL

## Summary

The migration now works correctly. URLs are stored as VARCHAR(2048) with an automatic SHA256 hash for fast, indexed lookups. This approach is:
- More performant
- Database-friendly
- Automatic (no manual hash management needed)
- Supports very long URLs
