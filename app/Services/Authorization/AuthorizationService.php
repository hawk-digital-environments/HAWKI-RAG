<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Document;
use App\Models\User;
use App\Services\Authorization\Contracts\PermissionGraphClient;
use App\Services\Authorization\Repositories\AuthorizationIdentityRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class AuthorizationService
{
    public function __construct(
        private ConfigRepository $config,
        private AuthorizationIdentityRepository $identities,
        private PermissionGraphClient $graph,
        private LoggerInterface $logger,
    ) {}

    public function documentApiEnforced(): bool
    {
        return (bool) $this->config->get('authz.document_api_enforced', false);
    }

    public function canViewDocument(?User $user, string $documentId): bool
    {
        if (! $this->documentApiEnforced()) {
            $this->audit('allowed', $user, $documentId, 'enforcement_disabled');

            return true;
        }

        if ($user === null) {
            $this->audit('denied', null, $documentId, 'missing_user');

            return false;
        }

        $context = $this->retrievalContextFor($user);
        $allowed = (bool) ($this->graph->batchCheckDocuments($context['provider'], $context['user_id'], [$documentId])[$documentId] ?? false);
        $this->audit($allowed ? 'allowed' : 'denied', $user, $documentId, $allowed ? 'permission_graph_allowed' : 'permission_graph_denied');

        return $allowed;
    }

    public function canViewDocumentModel(?User $user, Document $document): bool
    {
        return $this->canViewDocument($user, (string) $document->id);
    }

    /**
     * @return array{provider: string, user_id: string}|null
     */
    public function retrievalContextFor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $identity = $this->identities->findByUser($user);
        if ($identity !== null) {
            return [
                'provider' => $identity->provider,
                'user_id' => $identity->external_user_id,
            ];
        }

        return [
            'provider' => 'local',
            'user_id' => (string) $user->getAuthIdentifier(),
        ];
    }

    private function audit(string $decision, ?User $user, string $documentId, string $reason): void
    {
        $this->logger->info('authorization.document_access', [
            'decision' => $decision,
            'document_id' => $documentId,
            'user_id' => $user?->getAuthIdentifier(),
            'reason' => $reason,
        ]);
    }
}
