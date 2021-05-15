<?php

namespace Tests\Artisan;

use GitHooks\Configuration;
use Tests\Artisan\TestCase as IlluminateBaseTestCase;
use Tests\FileSystemTrait;
use Tests\RetroCompatibilityAssertsTrait;
use Tests\Utils\ConfigurationFake;
use Tests\Utils\ConfigurationFileBuilder;

abstract class ConsoleTestCase extends IlluminateBaseTestCase
{
    use CreatesApplication;
    use FileSystemTrait;
    use RetroCompatibilityAssertsTrait;

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
}
