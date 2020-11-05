<?php

namespace Tests;

/**
 * Simplifica el uso de la sobrecarga de clases
 */
class Mock extends \Mockery
{
    public static function overload(string $class)
    {
        return self::mock(sprintf('overload:%s', $class));
    }

    public static function alias(string $class)
    {
        return self::mock(sprintf('alias:%s', $class));
    }
}
