<?php

namespace Tests\System\Commands\Tools;

use GitHooks\Commands\Tools\ToolCommandExecutor;
use GitHooks\LoadTools\FastStrategy;
use GitHooks\Utils\GitFiles;
use GitHooks\Utils\GitFilesInterface;
use Illuminate\Container\Container;
use Mockery\MockInterface;
use Tests\Artisan\ConsoleTestCase;
use Tests\System\Utils\ConfigurationFileBuilder;
use Tests\System\Utils\GitFilesFake;
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
        parent::tearDown();
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
            ->containsStringInOutput('A TOTAL OF 3 ERRORS WERE FIXED IN 1 FILE');
    }

    /** @test */
    function it_can_be_override_execution_mode()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        file_put_contents($this->path . '/src/FileWithErrors.php', $this->fileBuilder->buildWithErrors(['phpcs']));

        $this->partialMock(GitFilesInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('getModifiedFiles')->andReturn([$this->path . '/src/FileWithErrors.php']);
        });

        // Alternative syntaxis to partialMock
        // $this->app->bind(GitFilesInterface::class, GitFilesFake::class);
        // $this->app->resolving(GitFilesFake::class, function ($gitFiles) {
        //     $gitFiles->setModifiedfiles([$this->path . '/src/FileWithErrors.php']);
        // });

        $commandUnderTheHood = "phpcbf $this->path/src/FileWithErrors.php";
        $this->artisan('tool:phpcs fast')
            ->containsStringInOutput($commandUnderTheHood)
            ->assertExitCode(1)
            ->containsStringInOutput('phpcbf - KO.')
            ->containsStringInOutput('A TOTAL OF 3 ERRORS WERE FIXED IN 1 FILE');
    }
}
