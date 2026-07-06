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
        private AuthorizationModeService $mode,
        private PermissionGraphClient $graph,
        private PermissionGraphRelationshipFactory $relationships,
        private PermissionEventRepository $events,
        private NativeGrantProjectionService $grants,
    ) {}

    /**
     * @param iterable<int, LmsMembership> $memberships
     * @param iterable<int, LmsDocumentRelation> $documentRelations
     * @return array<string, mixed>
     */
    public function sync(iterable $memberships, iterable $documentRelations): array
    {
        if (! $this->enabled()) {
            return [
                'relationships' => [],
                'graph' => [
                    'enabled' => false,
                    'ignored' => true,
                    'written' => 0,
                ],
                'native' => [
                    'groups_created' => 0,
                    'document_grants_created' => 0,
                    'group_members_upserted' => 0,
                ],
                'reconciliation' => [
                    'strategy' => 'no-op',
                    'stale_cleanup' => 'Authorization is disabled; connector inputs are accepted and ignored.',
                ],
            ];
        }

        $memberships = is_array($memberships) ? $memberships : iterator_to_array($memberships, false);
        $documentRelations = is_array($documentRelations) ? $documentRelations : iterator_to_array($documentRelations, false);
        $relationships = [];

        foreach ($memberships as $membership) {
            $this->events->recordMembership($membership);
            $relationships[] = $this->relationships->membership($membership);
        }

        foreach ($documentRelations as $relation) {
            $this->events->recordDocumentRelation($relation);
            $relationships[] = $this->relationships->documentRelation($relation);
        }

        $native = $this->grants->project($memberships, $documentRelations);

        return [
            'relationships' => array_map(fn ($relationship): array => $relationship->toArray(), $relationships),
            'graph' => $this->graph->writeRelationships($relationships),
            'native' => $native,
            'reconciliation' => [
                'strategy' => 'idempotent-upsert',
                'stale_cleanup' => 'Keep source_updated_at per normalized event; scheduled reconciliation can remove relationships absent from the latest connector snapshot.',
            ],
        ];
    }

    private function enabled(): bool
    {
        return $this->mode->enabled();
    }
}
