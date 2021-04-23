<?php

namespace Tests\System\Commands\Tools;

use Tests\Artisan\ConsoleTestCase;
use Tests\System\Utils\ConfigurationFileBuilder;
use Tests\System\Utils\PhpFileBuilder;

class CodeSnifferCommandTest extends ConsoleTestCase
{
    protected $configurationFile;

    protected $phpFileBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteDirStructure();

        $this->createDirStructure();

        $this->configurationFile = new ConfigurationFileBuilder($this->path);

        $this->fileBuilder = new PhpFileBuilder('File');

        $this->mockConfigurationFileForCommandsTests();
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
    }

    /** @test */
    function it_run_phpcs_command_without_errors()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        $this->artisan('tool:phpcs')
            ->assertExitCode(0)
            ->containsStringInOutput('phpcbf - OK.');
    }

    /** @test */
    function it_always_prints_by_console_the_command_that_it_executes_under_the_hood()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        $commandUnderTheHood = "phpcbf $this->path/src --standard=PSR12 --ignore=$this->path/vendor --error-severity=1 --warning-severity=6";

        $this->artisan('tool:phpcs')
            ->assertExitCode(0)
            ->containsStringInOutput($commandUnderTheHood);

        file_put_contents($this->path . '/src/FileWithErrors.php', $this->fileBuilder->buildWithErrors(['phpcs']));

        $this->artisan('tool:phpcs')
            ->assertExitCode(1)
            ->containsStringInOutput($commandUnderTheHood);
    }

    /** @test */
    function it_run_phpcs_command_with_fixable_errors()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->buildWithErrors(['phpcs']));

        $this->artisan('tool:phpcs')
            ->assertExitCode(1)
            ->containsStringInOutput('phpcbf - KO.')
            ->containsStringInOutput('The following errors have occurred:');
    }
    //3. sobreescribo la estrategia

}
