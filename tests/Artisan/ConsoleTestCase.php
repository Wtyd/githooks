<?php

namespace Tests\Artisan;

use Tests\Artisan\TestCase as IlluminateBaseTestCase;
use Tests\FileSystemTrait;
use Tests\MockConfigurationFileTrait;

abstract class ConsoleTestCase extends IlluminateBaseTestCase
{
    use CreatesApplication;
    use MockConfigurationFileTrait;
    use FileSystemTrait;
}
