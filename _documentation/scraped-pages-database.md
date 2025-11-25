# Scraped Pages Database Documentation

## Overview

The `scraped_pages` table stores all crawled web pages with comprehensive metadata, categorization, and access control information. This enables efficient querying, filtering, and access management for the scraped content.

---

## Database Schema

### Table: `scraped_pages`

#### Basic Page Information
| Field | Type | Nullable | Index | Description |
|-------|------|----------|-------|-------------|
| `id` | bigint | No | Primary | Auto-incrementing ID |
| `title` | string | Yes | Yes | Page title |
| `page_url` | text | No | Yes | URL of the scraped page |
| `meta_img_url` | text | Yes | No | Meta/OG image URL |

#### Content Information
| Field | Type | Nullable | Index | Description |
|-------|------|----------|-------|-------------|
| `images` | json | Yes | No | Array of image paths/URLs |
| `pdfs` | json | Yes | No | Array of PDF paths/URLs |
| `date` | string | Yes | No | Publication/update date from page |
| `path` | text | No | No | File system path to scraped content |
| `raw_json` | longtext | Yes | No | Complete JSON file content for reference |

#### Categorization Fields
| Field | Type | Nullable | Index | Description |
|-------|------|----------|-------|-------------|
| `site_category` | string | Yes | Yes | Category name (e.g., 'projekte_g_hawk', 'wiki_hawk') |
| `domain` | string | Yes | Yes | Base domain (e.g., 'hawk.de') |
| `subdomain` | string | Yes | Yes | Subdomain part (e.g., 'projekte.g', 'wiki') |
| `full_domain` | string | Yes | Yes | Complete domain (e.g., 'projekte.g.hawk.de') |

#### Access Control
| Field | Type | Nullable | Index | Description |
|-------|------|----------|-------|-------------|
| `access_level` | enum | No (default: 'internal') | Yes | Access level: 'public', 'internal', 'restricted', 'confidential' |

#### Crawler Metadata
| Field | Type | Nullable | Index | Description |
|-------|------|----------|-------|-------------|
| `crawler_label` | string | Yes | Yes | Label from crawler job |
| `crawler_job_id` | string | Yes | Yes | Job ID that created this entry |
| `crawled_at` | timestamp | Yes | Yes | When this was crawled |

#### Content Metadata
| Field | Type | Nullable | Index | Description |
|-------|------|----------|-------|-------------|
| `image_count` | integer | No (default: 0) | No | Count of images |
| `pdf_count` | integer | No (default: 0) | No | Count of PDFs |
| `content_length` | integer | Yes | No | Length of text content |

#### Search and Indexing
| Field | Type | Nullable | Index | Description |
|-------|------|----------|-------|-------------|
| `search_text` | text | Yes | Full-text | Processed text for full-text search |

#### System Fields
| Field | Type | Nullable | Index | Description |
|-------|------|----------|-------|-------------|
| `created_at` | timestamp | No | No | Record creation time |
| `updated_at` | timestamp | No | No | Record last update time |
| `deleted_at` | timestamp | Yes | No | Soft delete timestamp |

### Composite Indexes

- `(site_category, access_level)` - For filtering by category and access
- `(domain, subdomain)` - For filtering by domain parts
- `(crawler_label, crawled_at)` - For tracking crawler runs
- Full-text index on `(title, search_text)` - For search functionality

---

## Access Levels

### Access Level Hierarchy

```
public          < Anyone can access
    ↓
internal        < Authenticated users
    ↓
restricted      < Specific groups/roles
    ↓
confidential    < Highly restricted access
```

### Access Level Definitions

#### 1. **Public** (`public`)
- **Description**: Content that is publicly accessible to everyone
- **Use Cases**: Press releases, news articles, public events, general information
- **Keywords Detected**: public, news, press, blog, article, publication, announcement, event

#### 2. **Internal** (`internal`)
- **Description**: Content for authenticated university members (default level)
- **Use Cases**: Internal documentation, staff resources, student materials
- **Detection**: Default for hawk.de domains, general university content

#### 3. **Restricted** (`restricted`)
- **Description**: Content limited to specific groups or roles
- **Use Cases**: Department-specific content, project documentation, member areas
- **Keywords Detected**: admin, private, members, login, dashboard, management

#### 4. **Confidential** (`confidential`)
- **Description**: Highly sensitive content with strict access control
- **Use Cases**: Financial data, personal information, contracts, sensitive research
- **Keywords Detected**: confidential, secret, classified, sensitive, proprietary, financial

### Access Level Prediction

Access levels are automatically predicted based on:

1. **URL Analysis**: Path segments and query parameters
2. **Title Analysis**: Page title content
3. **Domain Patterns**: hawk.de vs external domains
4. **Keyword Matching**: Predefined keyword lists
5. **Path Patterns**: Common patterns like `/admin/`, `/public/`, `/members/`

---

## Site Categorization

### Category Naming Convention

Categories are automatically generated from domain names:

```
Domain: projekte.g.hawk.de
└─> Category: projekte_g_hawk

Domain: wiki.hawk.de
└─> Category: wiki_hawk

Domain: hawk.de
└─> Category: hawk
```

### Category Examples

| Domain | Category | Description |
|--------|----------|-------------|
| `projekte.g.hawk.de` | `projekte_g_hawk` | Project management system |
| `wiki.hawk.de` | `wiki_hawk` | University wiki |
| `www.hawk.de` | `www_hawk` | Main website |
| `portal.hawk.de` | `portal_hawk` | Student/staff portal |

### Domain Parts Extraction

For the URL `https://projekte.g.hawk.de/some/path`:

```php
[
    'full_domain' => 'projekte.g.hawk.de',
    'domain' => 'hawk.de',              // Last 2 parts
    'subdomain' => 'projekte.g',        // Everything before domain
    'site_category' => 'projekte_g_hawk'
]
```

---

## Model Usage

### Basic Queries

```php
use App\Models\ScrapedPage;

// Find by category
$pages = ScrapedPage::category('projekte_g_hawk')->get();

// Find by domain
$pages = ScrapedPage::domain('hawk.de')->get();

// Find by subdomain
$pages = ScrapedPage::subdomain('wiki')->get();

// Find by access level
$publicPages = ScrapedPage::accessLevel('public')->get();

// Find by crawler label
$pages = ScrapedPage::crawlerLabel('my-crawl-2024')->get();

// Full-text search
$pages = ScrapedPage::search('machine learning')->get();
```

### Advanced Queries

```php
// Public pages from specific domain
$pages = ScrapedPage::domain('hawk.de')
    ->accessLevel('public')
    ->get();

// Recent crawls of a category
$pages = ScrapedPage::category('wiki_hawk')
    ->where('crawled_at', '>=', now()->subDays(7))
    ->orderByDesc('crawled_at')
    ->get();

// Pages with PDFs
$pages = ScrapedPage::whereNotNull('pdfs')
    ->where('pdf_count', '>', 0)
    ->get();

// Pages by multiple access levels
$pages = ScrapedPage::accessLevel(['public', 'internal'])->get();
```

### Model Methods

```php
$page = ScrapedPage::find(1);

// Check access levels
$page->isPublic();        // bool
$page->isInternal();      // bool
$page->isRestricted();    // bool
$page->isConfidential();  // bool

// Get display name
$page->getSiteDisplayName(); // e.g., "projekte.g.hawk.de"

// Access raw JSON
$rawData = $page->raw_json; // Array
```

---

## Categorization Service

### PageCategorizationService

The `PageCategorizationService` handles automatic categorization and access level prediction.

#### Usage Example

```php
use App\Services\ScrapeService\Storage\PageCategorizationService;

$service = app(PageCategorizationService::class);

// Categorize a URL
$categorization = $service->categorize(
    url: 'https://projekte.g.hawk.de/project/123',
    data: $pageData,
    crawlerLabel: 'my-crawl',
    jobId: 'job-123'
);

// Returns:
[
    'site_category' => 'projekte_g_hawk',
    'domain' => 'hawk.de',
    'subdomain' => 'projekte.g',
    'full_domain' => 'projekte.g.hawk.de',
    'access_level' => 'internal',
    'crawler_label' => 'my-crawl',
    'crawler_job_id' => 'job-123',
    'crawled_at' => Carbon instance,
    'image_count' => 5,
    'pdf_count' => 2,
    'content_length' => 1500,
    'search_text' => 'extracted text...',
]
```

#### Statistics

```php
// Get category statistics
$stats = $service->getSiteCategoryStatistics();
// Returns: ['projekte_g_hawk' => 150, 'wiki_hawk' => 200, ...]

// Get access level statistics
$stats = $service->getAccessLevelStatistics();
// Returns: ['public' => 50, 'internal' => 300, ...]
```

#### Recategorization

```php
// Recategorize an existing page (useful after updating logic)
$page = ScrapedPage::find(1);
$service->recategorize($page);
```

---

## Migration

### Running the Migration

```bash
# Run the migration
php artisan migrate

# Rollback if needed
php artisan migrate:rollback
```

### Migration File

Location: `database/migrations/2025_11_18_115605_create_scraped_pages_table.php`

---

## Storage Integration

### Automatic Storage

When using `CrawlerPipelineService` with `storeInDatabase: true`:

```php
$result = $pipeline->execute(
    request: $request,
    storeInDatabase: true  // Automatically stores with categorization
);
```

### What Gets Stored

1. **All JSON Data**: Complete `data_XXXXX.json` content in `raw_json` field
2. **Categorization**: Automatic domain extraction and site categorization
3. **Access Level**: Predicted based on URL patterns and keywords
4. **Metadata**: Image/PDF counts, content length, crawler info
5. **Search Text**: Extracted text for full-text search

---

## Querying Examples

### By Category and Access

```php
// All public pages from wiki
$pages = ScrapedPage::category('wiki_hawk')
    ->accessLevel('public')
    ->get();

// Internal and above from projects site
$pages = ScrapedPage::category('projekte_g_hawk')
    ->accessLevel(['internal', 'restricted', 'confidential'])
    ->orderByDesc('crawled_at')
    ->paginate(20);
```

### By Crawler Job

```php
// All pages from a specific crawl
$pages = ScrapedPage::crawlerLabel('wiki-crawl-2024')
    ->with(['images', 'pdfs']) // If you have relationships
    ->get();

// Latest crawl per category
$latest = ScrapedPage::select('site_category')
    ->selectRaw('MAX(crawled_at) as latest_crawl')
    ->groupBy('site_category')
    ->get();
```

### Search with Filters

```php
// Search with category filter
$results = ScrapedPage::search('artificial intelligence')
    ->category('wiki_hawk')
    ->accessLevel(['public', 'internal'])
    ->orderByDesc('crawled_at')
    ->paginate(10);
```

---

## Access Control Integration (Future)

### When User Management is Implemented

```php
// Example middleware for access control
public function canAccess(User $user, ScrapedPage $page): bool
{
    return match($page->access_level) {
        ScrapedPage::ACCESS_PUBLIC => true,
        ScrapedPage::ACCESS_INTERNAL => $user->isAuthenticated(),
        ScrapedPage::ACCESS_RESTRICTED => $user->hasRole('staff'),
        ScrapedPage::ACCESS_CONFIDENTIAL => $user->hasRole('admin'),
    };
}
```

### Policy Example

```php
// app/Policies/ScrapedPagePolicy.php
public function view(User $user, ScrapedPage $page): bool
{
    return match($page->access_level) {
        ScrapedPage::ACCESS_PUBLIC => true,
        ScrapedPage::ACCESS_INTERNAL => $user !== null,
        ScrapedPage::ACCESS_RESTRICTED => $user->hasAnyRole(['staff', 'faculty']),
        ScrapedPage::ACCESS_CONFIDENTIAL => $user->hasRole('admin'),
    };
}
```

---

## Performance Considerations

### Indexes

The migration includes strategic indexes for common query patterns:
- `site_category` - For filtering by site
- `domain`, `subdomain` - For domain-based queries
- `access_level` - For access filtering
- `crawler_label`, `crawled_at` - For tracking crawls
- Composite indexes for combined queries
- Full-text index for search

### Optimization Tips

1. **Use Specific Queries**: Filter by category/domain first, then access level
2. **Pagination**: Always paginate large result sets
3. **Select Only Needed Columns**: Use `select()` to limit data transfer
4. **Cache Statistics**: Cache category/access level statistics
5. **Index Coverage**: Queries are optimized when they can use existing indexes

---

## Maintenance

### Recategorize All Pages

```php
use App\Services\ScrapeService\Storage\PageCategorizationService;
use App\Models\ScrapedPage;

$service = app(PageCategorizationService::class);

ScrapedPage::chunk(100, function($pages) use ($service) {
    foreach ($pages as $page) {
        $service->recategorize($page);
    }
});
```

### Clean Up Old Data

```php
// Delete pages older than 6 months
ScrapedPage::where('crawled_at', '<', now()->subMonths(6))
    ->delete(); // Soft delete

// Permanently delete soft-deleted pages
ScrapedPage::onlyTrashed()
    ->where('deleted_at', '<', now()->subMonth())
    ->forceDelete();
```

---

## API Response Example

```json
{
  "id": 1,
  "title": "Machine Learning Research Project",
  "page_url": "https://projekte.g.hawk.de/projects/ml-research",
  "site_category": "projekte_g_hawk",
  "domain": "hawk.de",
  "subdomain": "projekte.g",
  "full_domain": "projekte.g.hawk.de",
  "access_level": "internal",
  "crawler_label": "projects-2024",
  "crawler_job_id": "crawler_673b8...",
  "crawled_at": "2024-11-18T10:30:00Z",
  "image_count": 5,
  "pdf_count": 2,
  "meta_img_url": "https://projekte.g.hawk.de/images/project-thumb.jpg",
  "date": "2024-11-15",
  "created_at": "2024-11-18T10:35:00Z",
  "updated_at": "2024-11-18T10:35:00Z"
}
```

---

## Summary

The `scraped_pages` table provides:

✅ **Comprehensive Storage**: All page data and metadata
✅ **Automatic Categorization**: Domain-based site categorization
✅ **Access Control**: Four-level access system (ready for user management)
✅ **Search Capability**: Full-text search on title and content
✅ **Raw Data Preservation**: Complete JSON stored for reference
✅ **Performance**: Strategic indexes for common queries
✅ **Flexibility**: Query scopes for easy filtering
✅ **Future-Proof**: Soft deletes and extensible schema

The system is designed to integrate seamlessly with future user authentication and authorization systems while providing immediate value through automatic categorization and organization of scraped content.
