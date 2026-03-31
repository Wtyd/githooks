<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Tools\Tool\Phpstan;

/**
 * Class for testing purposes
 */
class PhpstanFake extends Phpstan
{
    use TestToolTrait;
}
