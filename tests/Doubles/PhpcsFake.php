<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcs;

/**
 * Class for testing purposes
 */
class PhpcsFake extends Phpcs
{
    use TestToolTrait;
}
