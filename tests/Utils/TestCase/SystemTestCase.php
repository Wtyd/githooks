<?php

namespace Tests\Utils\TestCase;

use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\FileUtilsFake;
use Tests\Utils\Traits\FileSystemTrait;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

abstract class SystemTestCase extends ConsoleTestCase
{
    use FileSystemTrait;

    public const TESTS_PATH = 'testsDir';

    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteDirStructure();

        // $date = new DateTime();
        // $token = strval($date->getTimestamp());
        $this->createDirStructure();

        $this->configurationFileBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);

        $this->app->bind(FileUtilsInterface::class, FileUtilsFake::class);
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
        parent::tearDown();
    }
}
