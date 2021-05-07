<?php

namespace Tests\Artisan;

use Tests\Artisan\TestCase as IlluminateBaseTestCase;
use Tests\FileSystemTrait;
use Tests\MockConfigurationFileTrait;
use PHPUnit\Runner\Version as PhpunitVersion;

abstract class ConsoleTestCase extends IlluminateBaseTestCase
{
    use CreatesApplication;
    use MockConfigurationFileTrait;
    use FileSystemTrait;

    protected $assertFileDoesNotExist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertFileDoesNotExist = $this->setAssertFileDoesNotExistForm();
    }

    protected function setAssertFileDoesNotExistForm()
    {
        if (version_compare(PhpunitVersion::id(), '9.0.0', '<')) {
            return 'assertFileNotExists';
        } else {
            return 'assertFileDoesNotExist';
        }
    }
}
