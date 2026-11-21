# Local permanent-job-v2 crawler

This is the explicit local cutover runtime. It exports CustomCrawler revision
`1c327244fed3b6f99ad08ad9203814942bc10f26` from an existing local Git repository
and applies `permanent-job-v2.patch`. The external checkout, its selected branch,
the old crawler container and production are untouched.

The pinned source supplies durable permanent admission, fsynced receipts, request
fingerprints, writer fences, cancellation/drainage and connection-bound HTTP plus
Linux-isolated Chromium. The patch adds the current protocol's required owner
fields, persists dataset/task identity, validates typed lifecycle receipts and
advertises the actual generated contract. It also denies Azure's platform address
at the connection boundary. RAWKI's contract validator is unchanged.

The local image reuses the installed `crawl4ai-fastapi:latest` dependency image;
the pinned source has the same dependency manifest and lockfile as that local
checkout. The new crawler package takes precedence through `PYTHONPATH`. This
Dockerfile is a local upgrade, not an independent production release recipe.

Prepare and build from the RAWKI repository root:

```sh
python_backend/.venv/bin/python scripts/prepare_local_crawler.py /path/to/CustomCrawler
docker compose -f docker-compose.yml -f docker-compose.cutover.local.yml build \
  rawki_local_crawler rawki_local_workflow_worker rawki_local_scraper_worker \
  rawki_local_converter_worker rawki_local_indexer_worker
```

Generated source is under ignored `.local-runtime/`, versioned by upstream commit,
patch and test/consumer content. The actual source patch, seccomp policy and its
upstream license are tracked here. The safe patch application is checked before
any source is changed.

The explicit local overlay routes Python admission and the gateway to
`rawki-local-crawler:8080`. It runs as the configured non-root UID/GID, has no host
ports or added capabilities, and uses the reviewed seccomp policy. Do not use
`privileged`, `seccomp=unconfined`, SYS_ADMIN or an ordinary browser fallback.
Readiness still performs the full unchanged `crawler_contract` check.

Start the crawler first and verify real Chromium as its intended service user:

```sh
docker compose -f docker-compose.yml -f docker-compose.cutover.local.yml \
  up -d --no-deps rawki_local_crawler
docker compose -f docker-compose.yml -f docker-compose.cutover.local.yml \
  exec -T rawki_local_crawler python - <<'PY'
import asyncio, json, os
from hawki_crawler.runtime.browser import SafeBrowser
async def probe():
    async with SafeBrowser() as browser:
        proof = {key: browser.proof[key] for key in ('netns', 'pidns', 'pid', 'interfaces')}
    assert browser.closed and browser.broker.closed
    assert browser.process.returncode is not None
    print(json.dumps({'uid': os.getuid(), 'proof': proof, 'drained': True}))
asyncio.run(probe())
PY
```

Then recreate the backend/gateway to apply the local endpoint and queue settings,
and start the local workers. `--no-deps` avoids recreating unrelated infrastructure;
it does not alter health checks or schema/authentication guards.

```sh
docker compose -f docker-compose.yml -f docker-compose.cutover.local.yml \
  up -d --no-deps hawki_backend hawki_rag_ui_gateway
docker compose -f docker-compose.yml -f docker-compose.cutover.local.yml \
  up -d --no-deps rawki_local_workflow_worker rawki_local_scraper_worker \
  rawki_local_converter_worker rawki_local_indexer_worker
```

These workers poll dedicated `rawki-local-*` queues and send the existing signed
callbacks directly to Python. They cannot consume historical queues. Historical
worker ownership still requires its separate drain/handoff.

Build and run the selected crawler lifecycle/network tests:

```sh
docker build --target test -f docker/crawler/Dockerfile \
  -t hawki-crawler:permanent-job-v2-test .local-runtime/crawler
docker run --rm hawki-crawler:permanent-job-v2-test
```

The browser security suite requires fixture-only address assignment. NET_ADMIN is
used only by this disposable test launcher; execution drops to `www-data` first.
The deployed crawler has no added capabilities.

```sh
docker run --rm --cap-add NET_ADMIN \
  --security-opt seccomp=./docker/crawler/browser-seccomp.json \
  -e REQUIRE_PROTECTED_BROWSER_TESTS=1 --entrypoint /bin/sh \
  hawki-crawler:permanent-job-v2-test -c '
    ip addr add 93.184.216.34/32 dev lo &&
    ip addr add 10.77.0.2/32 dev lo &&
    ip addr add 169.254.169.254/32 dev lo &&
    exec gosu www-data python -m pytest -q -p no:cacheprovider \
      /opt/rawki-crawler/tests/test_browser_egress.py
  '
```

For the full URL smoke, temporarily attach the controlled internal fixture network:

```sh
docker compose -f docker-compose.yml -f docker-compose.cutover.local.yml \
  -f docker-compose.crawler-smoke.yml up -d --no-deps \
  rawki_local_crawler crawler_smoke_fixture
python_backend/.venv/bin/python scripts/smoke_local_url_ingestion.py
docker compose -f docker-compose.yml -f docker-compose.cutover.local.yml \
  run --rm --no-deps --entrypoint python \
  -v "$PWD/scripts/audit_local_url_ingestion.py:/opt/audit.py:ro" \
  hawki_backend /opt/audit.py < /tmp/rawki-crawler-v2-url-smoke.json
```

The smoke creates a uniquely named Python dataset, redeems its real ingest grant,
uses the protected UI boundary, checks HTML/sitemap readiness and cancellation,
then retires its tasks and dataset through the API. The separate audit uses a
read-only database transaction and the actual crawler receipts. It checks exact
ownership, durable seals, removed artifacts and zero remaining Qdrant points.

Remove only the disposable fixture, restore the crawler's ordinary local network,
then repeat the audit and full readiness:

```sh
docker compose -f docker-compose.yml -f docker-compose.cutover.local.yml \
  -f docker-compose.crawler-smoke.yml rm -sf crawler_smoke_fixture
docker compose -f docker-compose.yml -f docker-compose.cutover.local.yml \
  up -d --no-deps rawki_local_crawler
docker network rm rawki_crawler_smoke
docker compose -f docker-compose.yml -f docker-compose.cutover.local.yml \
  exec -T hawki_backend python -m hawki_backend.readiness --live
```

Unknown legacy receipts remain unsealed and consume capacity until legitimately
reconciled. Never delete or mark them drained to create admission capacity.
