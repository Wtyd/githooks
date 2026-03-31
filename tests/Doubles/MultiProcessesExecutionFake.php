<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Tools\Process\Execution\MultiProcessesExecution;

class MultiProcessesExecutionFake extends MultiProcessesExecution
{
    use ExecutionFakeTrait;
}
