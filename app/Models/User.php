<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\User\Values\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * @property UserRole $role
 *
 * @method NewAccessToken createToken(string $name, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null)
 * @method MorphMany tokens()
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'email',
        'ip',
        'isRemoved',
    ];

    protected $attributes = [
        'role' => 'user',
    ];

    protected $casts = [
        'isRemoved' => 'bool',
        'role' => UserRole::class,
    ];

    public function remove(): void
    {
        $this->isRemoved = true;
    }
}
