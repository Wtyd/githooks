<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Tools\Process\Execution\ProcessExecution;

class ProcessExecutionFake extends ProcessExecution
{
    use ExecutionFakeTrait;
}
