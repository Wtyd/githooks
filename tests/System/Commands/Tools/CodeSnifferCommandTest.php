<?php

namespace Tests\System\Commands\Tools;

use GitHooks\Utils\GitFilesInterface;
use Mockery\MockInterface;
use Tests\Artisan\ConsoleTestCase;
use Tests\Utils\PhpFileBuilder;

class CodeSnifferCommandTest extends ConsoleTestCase
{
    protected $phpFileBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileBuilder = new PhpFileBuilder('File');
    }

    /** @test */
    function it_run_phpcs_command_without_errors()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        $this->artisan('tool:phpcs')
            ->assertExitCode(0)
            ->containsStringInOutput('phpcbf - OK.');
    }

    /** @test */
    function it_always_prints_by_console_the_command_that_it_executes_under_the_hood()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

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
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->buildWithErrors(['phpcs']));

        $this->artisan('tool:phpcs')
            ->assertExitCode(1)
            ->containsStringInOutput('phpcbf - KO.')
            ->containsStringInOutput('A TOTAL OF 3 ERRORS WERE FIXED IN 1 FILE');
    }

    /** @test */
    function it_can_be_override_execution_mode()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        file_put_contents($this->path . '/src/FileWithErrors.php', $this->fileBuilder->buildWithErrors(['phpcs']));

        $this->partialMock(GitFilesInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('getModifiedFiles')->andReturn([$this->path . '/src/FileWithErrors.php']);
        });

        $commandUnderTheHood = "phpcbf $this->path/src/FileWithErrors.php";
        $this->artisan('tool:phpcs fast')
            ->containsStringInOutput($commandUnderTheHood)
            ->assertExitCode(1)
            ->containsStringInOutput('phpcbf - KO.')
            ->containsStringInOutput('A TOTAL OF 3 ERRORS WERE FIXED IN 1 FILE');
    }
}
