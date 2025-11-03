<?php

namespace App\Console\Commands\UserManagement;

use App\Models\User;
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
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $username = $this->ask('Enter the username?');
        $email = $this->ask('Enter the email?');
        $ip = $this->ask('Enter the server ip address?');

        User::create([
            'username' => $username,
            'email' => $email,
            'ip' => $ip,
        ]);
    }
}
