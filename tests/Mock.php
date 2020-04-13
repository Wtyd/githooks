<?php

namespace Tests;

use Mockery;

/**
 * Simplifica el uso de la sobrecarga de clases
 */
class Mock extends Mockery
{
    public function overload(string $class)
    {
        return self::mock(sprintf('overload:%s', $class));
    }
}
