<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class SpecV2Service
{
    public function __construct(
        public TenantService $tenants,
        public ApplicationService $applications,
        public HeapService $heaps,
        public DocumentService $documents,
        public CorpusService $corpora,
        public GroupService $groups,
        public AuthorizationGrantService $auth,
    ) {}
}
