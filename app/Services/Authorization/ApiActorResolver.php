<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\SpecV2\Application;
use App\Models\User;
use App\Services\SpecV2\Repositories\ApplicationRepository;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Request;
use LogicException;

#[Singleton]
readonly class ApiActorResolver
{
    public function __construct(
        private IdentityProvisioningService $identityProvisioning,
        private ApplicationRepository $applications,
    ) {}

    public function resolve(Request $request): ApiActor
    {
        return $this->resolvePrincipal($request->user());
    }

    public function resolvePrincipal(mixed $principal): ApiActor
    {
        if ($principal instanceof Application) {
            return ApiActor::forApplication($principal);
        }

        if (! $principal instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        $identity = $this->identityProvisioning->actorForUser($principal);
        $application = $identity?->application_id !== null
            ? $this->applications->findById((string) $identity->application_id)
            : null;

        if (! $application instanceof Application) {
            throw new LogicException('Authenticated user is missing an application context.');
        }

        return ApiActor::forUser($principal, $application, $identity);
    }
}
