<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Services\Authorization\Values\RetrievalAuthorizationContext;
use App\Services\Rag\Values\RagQueryPayload;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class RagQueryPayloadFactory
{
    /**
     * @param array<string, mixed> $input
     */
    public function make(array $input, ?RetrievalAuthorizationContext $authContext = null): RagQueryPayload
    {
        return RagQueryPayload::fromInput($input, $authContext);
    }
}
