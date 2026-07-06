<?php

/*
|--------------------------------------------------------------------------
| Internal API Routes
|--------------------------------------------------------------------------
| Route registration is split by product boundary:
| - app_search.php: application-facing retrieval/search
| - app_ingestion.php: application-facing ingestion entrypoints
| - compatibility.php: legacy/open compatibility adapters
| - spec_v2.php: canonical tenant/application/heap/corpus/group/auth APIs
| - operator.php: human operator and pipeline control surfaces
*/

require __DIR__.'/internal_api/app_search.php';
require __DIR__.'/internal_api/app_ingestion.php';
require __DIR__.'/internal_api/compatibility.php';
require __DIR__.'/internal_api/spec_v2.php';
require __DIR__.'/internal_api/operator.php';
