# RAWKI RAG Web Bruno Collection

This OpenCollection YAML scaffold contains Bruno request files for the web scrape and crawler endpoints.

## Use in Bruno

1. Open `bruno/rag-web` as an OpenCollection collection.
2. Select the `local` environment.
3. Review placeholder values such as `url`, `label`, `outputDir`, `jobId`, and `taskId` before running mutation requests.

The local environment defaults to `http://localhost:8080` for `baseUrl`.

## CLI

Use read-only requests first:

```bash
cd bruno/rag-web
bru run requests/web-scrape/009-list-crawler-jobs.yml --env local
```

Avoid running scrape, delete, cancel, pause, or resume requests against shared data unless you intend to change crawler state.
