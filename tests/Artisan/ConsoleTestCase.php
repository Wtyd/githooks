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

    public function __construct()
    {
        parent::__construct();

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

    // /**
    //  * Asserts that a file does not exist.
    //  * Wrapper for phpunit's method. This allows to use the old way (assertFileNotExists) deprecated in phpunit 10.
    //  *
    //  * @throws ExpectationFailedException
    //  * @throws \SebastianBergmann\RecursionContext\InvalidArgumentException
    //  * @return void
    //  */
    // public static function assertFileDoesNotExist(string $filename, string $message = ''): void
    // {
    //     $assertFileDoesNotExist = '';
    //     if (version_compare(PhpunitVersion::id(), '9.0.0', '<')) {
    //         $assertFileDoesNotExist = 'assertFileNotExists';
    //     } else {
    //         $assertFileDoesNotExist = 'assertFileDoesNotExist';
    //     }
    //     // $assertFileDoesNotExist = $this->assertFileDoesNotExist;
    //     self::$assertFileDoesNotExist($filename, $message);
    // }
}
