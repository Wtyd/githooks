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

    protected static $assertFileDoesNotExist;

    /**
     * @param int|string $dataName
     *
     * @internal This method is not covered by the backward compatibility promise for PHPUnit
     */
    public function __construct(?string $name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        self::$assertFileDoesNotExist = self::setAssertFileDoesNotExistForm();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteDirStructure();
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
        parent::setUp();
    }

    protected static function setAssertFileDoesNotExistForm()
    {
        if (version_compare(PhpunitVersion::id(), '9.0.0', '<')) {
            return 'assertFileNotExists';
        } else {
            return 'assertFileDoesNotExist';
        }
    }
}
