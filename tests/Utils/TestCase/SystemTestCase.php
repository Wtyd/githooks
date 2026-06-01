<?php

namespace Tests\Utils\TestCase;

use Tests\Doubles\FileUtilsFake;
use Tests\Utils\ConfigurationFileBuilder;
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

        // Default: FileUtilsFake bound transitorio. The commands resolve their runner
        // lazily at handle(), so each run gets a fresh empty fake (→ FAST modes see no
        // changes, deterministic, never the real git index). Transitorio — not a shared
        // instance — so the container's `resolving(FileUtilsFake::class, ...)` callbacks
        // fire on every resolution (ExecuteToolCommandTest configures the fake that way).
        // A flow test that needs to control the change set binds its own configured
        // instance (`$this->app->instance(FileUtilsInterface::class, $fake)`), which the
        // lazy runner then resolves. Tests needing the real FileUtils re-bind it to
        // FileUtils in their own setUp (e.g. JsonInputFilesContractTest).
        $this->app->bind(FileUtilsInterface::class, FileUtilsFake::class);
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
        parent::tearDown();
    }
}
