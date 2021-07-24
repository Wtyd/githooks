<?php

namespace Tests\System\Commands\Tools;

use Tests\ConsoleTestCase;
use Tests\Utils\FileUtilsFake;
use Tests\Utils\PhpFileBuilder;

class CodeSnifferCommandTest extends ConsoleTestCase
{
    protected $phpFileBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileBuilder = new PhpFileBuilder('File');
    }

    //1 Ejecuta la herramienta pasada por parametro (cualquier herramienta de las soportadas)
    //4 printa el comando que ejecuta
    //2 Excepcion por ejecutar una tool no soportada
    // 3 Excepcion porque la herramienta a ejecutar no está configurada
    //3 ExitCode 1 cuando falla cualquier herramienta (de una en una o varias juntas)

    // 5 se puede sobreescribir el modo de ejecución fast a full y full a fast

    /** @test */
    function it_run_phpcs_command_without_errors()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        $this->artisan('tool phpcs')
            ->assertExitCode(0)
            ->containsStringInOutput('phpcbf - OK.');
    }

    /** @test */
    function it_always_prints_by_console_the_command_that_it_executes_under_the_hood()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        $commandUnderTheHood = "phpcbf $this->path/src --standard=PSR12 --ignore=$this->path/vendor --error-severity=1 --warning-severity=6";

        $this->artisan('tool phpcs')
            ->assertExitCode(0)
            ->containsStringInOutput($commandUnderTheHood);

        file_put_contents($this->path . '/src/FileWithErrors.php', $this->fileBuilder->buildWithErrors(['phpcs']));

        $this->artisan('tool phpcs')
            ->assertExitCode(1)
            ->containsStringInOutput($commandUnderTheHood);
    }

    /** @test */
    function it_run_phpcs_command_with_fixable_errors()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->buildWithErrors(['phpcs']));

        $this->artisan('tool phpcs')
            ->assertExitCode(1)
            ->containsStringInOutput('phpcbf - KO.')
            ->containsStringInOutput('A TOTAL OF 3 ERRORS WERE FIXED IN 1 FILE');
    }

    /**
     * @test
     * 1. It creates a githooks.yml with the standard configuration (PSR12 rules, path src, etc)
     * 2. It creates two files: one without erros and another with errors.
     * 3. It add to git stage only the file without errors.
     * 4. It runs the command with the option 'fast'.
     * 5. It overrides the execution: phpcs only runs over files added to git stage (the file with errors) instead 'src'
     */
    function it_can_be_override_full_execution_from_githooksyml_for_fast_execution_from_cli()
    {
        file_put_contents($this->path . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        $pathForFileWithoutErrors = $this->path . '/src/File.php';
        file_put_contents($pathForFileWithoutErrors, $this->fileBuilder->build());

        $pathForFileWithErrors = $this->path . '/src/FileWithErrors.php';
        file_put_contents($pathForFileWithErrors, $this->fileBuilder->buildWithErrors(['phpcs']));

        $this->app->resolving(FileUtilsFake::class, function ($gitFiles) use ($pathForFileWithoutErrors) {
            $gitFiles->setModifiedfiles([$pathForFileWithoutErrors]);
            $gitFiles->setFilesThatShouldBeFoundInDirectories([$pathForFileWithoutErrors]);
        });

        $commandUnderTheHood = "phpcbf $pathForFileWithoutErrors";
        $this->artisan('tool phpcs fast')
            ->containsStringInOutput($commandUnderTheHood)
            ->containsStringInOutput('phpcbf - OK.');
    }
}
