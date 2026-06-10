<?php

namespace App\Console\Commands\UserManagement;

use Illuminate\Console\Command;
use App\Services\User\Repositories\UserRepository;

class RemoveUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:remove';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Removes User From Database';

    /**
     * Execute the console command.
     */
    public function handle(UserRepository $users): void
    {
        // Ask for confirmation
        if ($this->confirm('The user and all the related messages will be permanently removed. Do you want to continue?', true)) {
            // Present options
            $choice = $this->choice(
                'How would you like to identify the user?',
                ['Username', 'Email Address', 'UserID'],
                0
            );

            // Ask for the respective value
            $value = (string) $this->ask("Please enter the $choice");
            $user = $this->findUser($users, $choice, $value);

            if (! $user) {
                $this->error('User not found!');
                return;
            }
            if ((bool) $user->isRemoved) {
                $this->error('User is already removed!');
                return;
            }
            $users->markRemoved($user);

            $this->info('Profile Reset Successfull!');

        } else {
            $this->info('Command operation cancelled.');
        }
    }

    private function findUser(UserRepository $users, string $choice, string $value)
    {
        return match ($choice) {
            'Username' => $users->findByUsername($value),
            'Email Address' => $users->findByEmail($value),
            'UserID' => $users->findById($value),
            default => null,
        };
    }
}
