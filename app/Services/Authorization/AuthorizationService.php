<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Document;
use App\Models\User;
use App\Services\Authorization\Contracts\PermissionGraphClient;
use App\Services\Authorization\Repositories\UserIdentityRepository;
use App\Services\Authorization\Values\RetrievalAuthorizationContext;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class AuthorizationService
{
    public function __construct(
        private ConfigRepository $config,
        private UserIdentityRepository $identities,
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
        if ($context === null) {
            $this->audit('denied', $user, $documentId, 'missing_auth_context');

            return false;
        }

        $allowed = (bool) ($this->graph->batchCheckDocuments($context->provider, $context->userId, [$documentId])[$documentId] ?? false);
        $this->audit($allowed ? 'allowed' : 'denied', $user, $documentId, $allowed ? 'permission_graph_allowed' : 'permission_graph_denied');

        return $allowed;
    }

    public function canViewDocumentModel(?User $user, Document $document): bool
    {
        return $this->canViewDocument($user, (string) $document->id);
    }

    public function retrievalContextFor(?User $user): ?RetrievalAuthorizationContext
    {
        if ($user === null) {
            return null;
        }

        $identity = $this->identities->findByUser($user);
        if ($identity !== null) {
            return RetrievalAuthorizationContext::fromIdentity($identity);
        }

        return RetrievalAuthorizationContext::forLocalUser($user);
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
