<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Services\Authorization\Contracts\PermissionGraphClient;
use App\Services\Authorization\PermissionGraph\PermissionGraphRelationshipFactory;
use App\Services\Authorization\Repositories\PermissionEventRepository;
use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PermissionSyncService
{
    public function __construct(
        private PermissionGraphClient $graph,
        private PermissionGraphRelationshipFactory $relationships,
        private PermissionEventRepository $events,
    ) {}

    /**
     * @param iterable<int, LmsMembership> $memberships
     * @param iterable<int, LmsDocumentRelation> $documentRelations
     * @return array<string, mixed>
     */
    public function sync(iterable $memberships, iterable $documentRelations): array
    {
        $relationships = [];

        foreach ($memberships as $membership) {
            $this->events->recordMembership($membership);
            $relationships[] = $this->relationships->membership($membership);
        }

        foreach ($documentRelations as $relation) {
            $this->events->recordDocumentRelation($relation);
            $relationships[] = $this->relationships->documentRelation($relation);
        }

        return [
            'relationships' => array_map(fn ($relationship): array => $relationship->toArray(), $relationships),
            'graph' => $this->graph->writeRelationships($relationships),
            'reconciliation' => [
                'strategy' => 'idempotent-upsert',
                'stale_cleanup' => 'Keep source_updated_at per normalized event; scheduled reconciliation can remove relationships absent from the latest connector snapshot.',
            ],
        ];
    }
}
