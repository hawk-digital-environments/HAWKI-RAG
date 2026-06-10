<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Health;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineHealthResultFactory
{
    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function ok(string $name, string $detail): array
    {
        return [
            'name' => $name,
            'status' => 'ok',
            'detail' => $detail,
            'fix' => '',
        ];
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function failure(string $name, string $detail, string $fix): array
    {
        return [
            'name' => $name,
            'status' => 'fail',
            'detail' => $detail,
            'fix' => $fix,
        ];
    }
}
