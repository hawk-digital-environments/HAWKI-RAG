# Local permanent-job-v2 crawler

The local crawler uses pinned CustomCrawler revision `1c327244fed3b6f99ad08ad9203814942bc10f26` plus the tracked protocol patch. Its API genuinely implements durable admission, ownership fingerprints, typed lifecycle receipts, cancellation/drainage and protected transport.

Follow [the crawler build and operations guide](../../docker/crawler/README.md) for reproducible source preparation, images, seccomp, non-root execution, lifecycle/network tests and disposable URL acceptance.

The backend and local workers use `http://rawki-local-crawler:8080` on Docker networks. No crawler host port or elevated runtime capability is required. Readiness performs the full `crawler_contract` check; URL ingestion remains enabled and fails closed when the contract is unavailable.

The local API does not include a saved-profile/task UI. An empty optional catalog is distinct from failed execution readiness. Historical archived records keep their identity/root reservations and cannot be sealed or adopted as new work. Preserve original artifacts and the offline archive sidecar.
