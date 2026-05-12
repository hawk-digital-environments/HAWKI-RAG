<?php

namespace App\Support;

final class PipelineExitCode
{
    public const SUCCESS = 0;
    public const RUNTIME_FAILURE = 1;
    public const VALIDATION_FAILURE = 2;
    public const PARTIAL_SUCCESS = 3;
}
