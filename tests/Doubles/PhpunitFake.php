<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Tools\Tool\Phpunit;

/**
 * Clase fake para pruebas unitarias de Phpunit
 */
class PhpunitFake extends Phpunit
{
    use TestToolTrait;
}
