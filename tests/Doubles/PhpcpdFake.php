<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Tools\Tool\Phpcpd;

/**
 * Class for testing purposes
 */
class PhpcpdFake extends Phpcpd
{
    use TestToolTrait;
}
