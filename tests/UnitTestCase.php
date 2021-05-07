<?php

namespace Tests;

use GitHooks\Commands\Console\RegisterBindings;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class UnitTestCase extends TestCase
{

    protected function setUp(): void
    {
        $registerBindings = new RegisterBindings();
        $registerBindings();
    }
}
