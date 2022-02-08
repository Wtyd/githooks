<?php

namespace Tests\Utils\TestCase;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Container\RegisterBindings;

class UnitTestCase extends TestCase
{
    protected function registerBindings()
    {
        $register = new RegisterBindings();
        $register->register();
    }
}
