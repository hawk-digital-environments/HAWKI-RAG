<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

readonly class OperatorAccessService
{
    public function __construct(
        private AuthFactory $auth,
        private GateContract $gate,
    ) {}

    public function allows(Request $request): bool
    {
        $resolvedUser = $request->user() ?? $this->auth->guard('sanctum')->user();
        $user = $resolvedUser instanceof User ? $resolvedUser : null;
        $gate = $user === null ? $this->gate : $this->gate->forUser($user);

        if ($gate->denies('access-operator')) {
            return false;
        }

        if ($user !== null) {
            $request->setUserResolver(static fn (): User => $user);
        }

        return true;
    }
}
