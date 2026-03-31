<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Tools\Tool\Psalm;

/**
 * Clase fake para pruebas unitarias de Psalm
 */
class PsalmFake extends Psalm
{
    use TestToolTrait;
}
