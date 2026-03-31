<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Tools\Tool\ParallelLint;

/**
 * Class for testing purposes
 */
class ParallelLintFake extends ParallelLint
{
    use TestToolTrait;
}
