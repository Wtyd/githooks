<?php

namespace Tests\System\Commands\Tools;

use GitHooks\Commands\Tools\CodeSnifferCommand;
use GitHooks\Tools\CheckSecurity;
use GitHooks\Tools\Errors;
use Illuminate\Container\Container;
use Mockery;
use Mockery\MockInterface;
use Tests\Artisan\ConsoleTestCase;
use Tests\Mock;
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
        // Establecer el fichero de configuración (me da igual solo phpcs o mas pq el comando solo ejecuta phpcs)
        // Establecer un fichero a ser validado. Si es uno que tenga errores, me aseguro que lo tiro contra ese aunque pueda tardar un poco mas. Si es un fichero correcto podría "equivocarme en el test" y tirarlo contra otra ruta.
        // Tengo que sustituir las rutas tanto del fichero de configuración como la de los ficheros a evaluar
        file_put_contents($this->path . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->buildWithErrors(['phpcs']));

        $this->artisan('tool:phpcs')->assertExitCode(1)
            ->containsStringInOutput("A TOTAL OF 3 ERRORS WERE FIXED IN");
    }
    //1. error
    //2. no error
    //3. sobreescribo la estrategia

}
