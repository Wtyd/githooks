<?php

namespace Tests\Artisan;

use GitHooks\Configuration;
use Tests\Artisan\TestCase as IlluminateBaseTestCase;
use Tests\FileSystemTrait;
use PHPUnit\Runner\Version as PhpunitVersion;
use Tests\Utils\ConfigurationFake;
use Tests\Utils\ConfigurationFileBuilder;

abstract class ConsoleTestCase extends IlluminateBaseTestCase
{
    use CreatesApplication;
    use FileSystemTrait;

    protected static $assertFileDoesNotExist;

    /**
     * @var ConfigurationFileBuilder
     */
    protected $configurationFileBuilder;

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

        $this->createDirStructure();

        $this->app->bind(Configuration::class, ConfigurationFake::class);

        $this->configurationFileBuilder = new ConfigurationFileBuilder($this->path);
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
        parent::tearDown();
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
