<?php

namespace Tests;

use Tests\System\Utils\Console\TestCase as BaseTestCase;
use Tests\System\Utils\Console\CreatesApplication;

abstract class ConsoleTestCase extends BaseTestCase
{
    use CreatesApplication;
}
