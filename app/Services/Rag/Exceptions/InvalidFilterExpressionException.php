<?php

declare(strict_types=1);

namespace App\Services\Rag\Exceptions;

use Illuminate\Validation\ValidationException;

class InvalidFilterExpressionException extends ValidationException
{
    public static function withMessage(string $message): self
    {
        return new self(validator: validator([], []), response: null, errorBag: 'default');
    }
}
