<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Tools\Tool\Phpmd;

/**
 * Class for testing purposes
 */
class PhpmdFake extends Phpmd
{
    use TestToolTrait;
}
