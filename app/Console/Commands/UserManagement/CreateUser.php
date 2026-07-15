<?php

namespace App\Console\Commands\UserManagement;

use App\Services\User\Repositories\UserRepository;
use Illuminate\Console\Command;

class CreateUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a local user and print the ID used by grant commands';

    /**
     * Execute the console command.
     */
    public function handle(UserRepository $users): void
    {
        $username = (string) $this->ask('Enter the username?');
        $email = (string) $this->ask('Enter the email?');
        $ip = (string) $this->ask('Enter the server ip address?');

        $user = $users->create($username, $email, $ip);

        $this->info(sprintf('User created successfully (ID: %s).', $user->getAuthIdentifier()));
    }
}
