<?php

declare(strict_types=1);

namespace App\Console\Commands\UserManagement;

use App\Models\User;
use App\Services\Profile\ApiTokenService;
use App\Services\Profile\Values\ApiTokenAbility;
use App\Services\User\Repositories\UserRepository;
use Illuminate\Console\Command;

class CreateSanctumTokenForUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:token
        {--revoke : Revoke a token instead of creating one}
        {--abilities=query : Comma-separated token abilities: query, admin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or revoke Sanctum API tokens for a user';

    /**
     * {@inheritDoc}
     */
    public function __construct(
        private ApiTokenService $apiTokenService,
        private UserRepository $users,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isRevoking = $this->option('revoke');
        $actionText = $isRevoking ? 'revoke' : 'create';

        $this->info("You are about to $actionText an API token for a user.");

        // Present options to identify the user
        $choice = $this->choice(
            'How would you like to identify the user?',
            ['Username', 'Email Address', 'UserID'],
            0
        );

        // Ask for the respective value
        $value = (string) $this->ask("Please enter the $choice");
        $user = $this->findUser($choice, $value);

        if (! $user) {
            $this->error('User not found!');

            return self::FAILURE;
        }

        if ((bool) $user->isRemoved) {
            $this->error('User account is suspended!');

            return self::FAILURE;
        }

        // Simulate authentication for the user
        auth()->setUser($user);

        if ($isRevoking) {
            // List existing tokens
            $this->listUserTokens();

            $tokenId = $this->ask('Enter the token ID to revoke');
            try {
                // Call the revoke method
                $this->apiTokenService->revokeToken((int) $tokenId);
                $this->info('Token successfully revoked.');
            } catch (\Throwable $e) {
                $this->error('Failed to revoke token. '.$e->getMessage());

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        // Create a token
        $tokenName = $this->ask('Enter a name for the token (max 16 characters)');
        $abilities = $this->tokenAbilities();
        if ($abilities === null) {
            return self::FAILURE;
        }

        $token = $this->apiTokenService->createApiToken((string) $tokenName, $abilities);

        $this->info('Token created successfully:');
        $this->line('');
        $this->line('Token ID: '.$token->accessToken->id);
        $this->line('Token Name: '.$token->accessToken->name);
        $this->line('');
        $this->warn('IMPORTANT: Copy this token now - it will not be shown again!');
        $this->line('');
        $this->info($token->plainTextToken);
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * List tokens for the user
     */
    private function listUserTokens(): void
    {
        $tokens = $this->apiTokenService->fetchTokenList();

        if ($tokens->isEmpty()) {
            $this->warn('No tokens found for this user.');

            return;
        }

        $this->info('Available tokens:');
        $headers = ['ID', 'Name', 'Abilities'];
        $rows = [];

        foreach ($tokens as $token) {
            $rows[] = [
                $token['id'],
                $token['name'],
                implode(', ', $token['abilities']),
            ];
        }

        $this->table($headers, $rows);
    }

    private function findUser(string $choice, string $value): ?User
    {
        return match ($choice) {
            'Username' => $this->users->findByUsername($value),
            'Email Address' => $this->users->findByEmail($value),
            'UserID' => $this->users->findById($value),
            default => null,
        };
    }

    /**
     * @return non-empty-list<ApiTokenAbility>|null
     */
    private function tokenAbilities(): ?array
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn (string $ability): string => strtolower(trim($ability)),
            explode(',', (string) $this->option('abilities')),
        ))));

        if ($values === []) {
            $this->error('At least one token ability is required.');

            return null;
        }

        $abilities = [];
        foreach ($values as $value) {
            $ability = ApiTokenAbility::tryFrom($value);
            if ($ability === null) {
                $this->error("Token ability {$value} is invalid. Expected query or admin.");

                return null;
            }

            $abilities[] = $ability;
        }

        return $abilities;
    }
}
