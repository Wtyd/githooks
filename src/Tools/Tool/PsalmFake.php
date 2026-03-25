<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\Tools\Tool\TestToolTrait;

/**
 * Clase fake para pruebas unitarias de Psalm
 */
class PsalmFake extends Psalm
{
    use TestToolTrait;
}
