<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Tools\Tool\CodeSniffer\Phpcbf;

/**
 * Class for testing purposes
 */
class PhpcbfFake extends Phpcbf
{
    use TestToolTrait;
}
