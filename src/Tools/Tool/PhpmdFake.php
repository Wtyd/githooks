<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\Tools\Tool\TestToolTrait;

/**
 * Class for testing purposes
 */
class PhpmdFake extends Phpmd
{
    use TestToolTrait;
}
