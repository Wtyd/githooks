<?php

namespace Tests;

use Tests\Zero\PendingCommand;
use Tests\FileSystemTrait;
use Tests\RetroCompatibilityAssertsTrait;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\FileUtilsFake;
use Tests\Zero\ZeroTestCase;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

abstract class ConsoleTestCase extends ZeroTestCase
{
    use CreatesApplication;
    use FileSystemTrait;
    use RetroCompatibilityAssertsTrait;

    /**
     * The string is contained in the output.
     *
     * @var array
     */
    public $containsStringInOutput = [];

    /**
     * The string is contained in the output.
     *
     * @var array
     */
    public $notContainsStringInOutput = [];

    public $expectedTables = [];

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

        $this->configurationFileBuilder = new ConfigurationFileBuilder($this->path);

        $this->app->bind(FileUtilsInterface::class, FileUtilsFake::class);
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
        parent::tearDown();
    }

    /**
     * Call artisan command and return code.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return Tests\Artisan\PendingCommand|int
     */
    public function artisan($command, $parameters = [])
    {
        if (!$this->mockConsoleOutput) {
            return $this->app[Kernel::class]->call($command, $parameters);
        }

        return new PendingCommand($this, $this->app, $command, $parameters);
    }
}
