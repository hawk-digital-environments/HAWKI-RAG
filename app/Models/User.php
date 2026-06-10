<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\HasApiTokens;

/**
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

    protected $casts = [
        'isRemoved' => 'bool',
    ];

    public function remove(): void
    {
        $this->isRemoved = true;
    }
}
