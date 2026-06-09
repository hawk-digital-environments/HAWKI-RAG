<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Architecture;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineArchitectureService
{
    public function __construct(
        private PipelineArchitectureEventCatalog $events,
        private PipelineArchitectureTopologyBuilder $topology,
        private PipelineArchitectureFailureModeCatalog $failureModes,
        private PipelineArchitectureRuntimeCatalog $runtime,
    ) {
    }

    public function summary(): array
    {
        return [
            'events' => $this->events(),
            'flow' => $this->flow(),
            'topology' => $this->topology(),
            'handlers' => $this->handlers(),
            'persistence' => $this->persistence(),
            'idempotency' => $this->idempotency(),
            'recovery' => $this->recovery(),
            'health' => $this->health(),
            'testing' => $this->testing(),
            'failureModes' => $this->failureModes(),
            'mentalModel' => $this->mentalModel(),
        ];
    }

    public function events(): array
    {
        return $this->events->events();
    }

    public function flow(): array
    {
        return $this->events->flow();
    }

    public function topology(): array
    {
        return $this->topology->topology();
    }

    public function failureModes(): array
    {
        return $this->failureModes->failureModes();
    }

    public function handlers(): array
    {
        return $this->runtime->handlers();
    }

    public function persistence(): array
    {
        return $this->runtime->persistence();
    }

    public function idempotency(): array
    {
        return $this->runtime->idempotency();
    }

    public function recovery(): array
    {
        return $this->runtime->recovery();
    }

    public function health(): array
    {
        return $this->runtime->health();
    }

    public function testing(): array
    {
        return $this->runtime->testing();
    }

    public function mentalModel(): array
    {
        return $this->runtime->mentalModel();
    }
}
