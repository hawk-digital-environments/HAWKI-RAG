<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function actingAsApiUser(): User
    {
        $user = User::query()->create([
            'username' => 'api-test-'.uniqid(),
            'email' => 'api-test-'.uniqid().'@example.test',
            'ip' => '127.0.0.'.random_int(1, 254),
        ]);

        Sanctum::actingAs($user);

        return $user;
    }
}
