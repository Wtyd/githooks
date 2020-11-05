<?php

namespace Tests\System;

use GitHooks\GitHooks;
use GitHooks\Tools\CheckSecurity;
use GitHooks\Utils\GitFiles;
use Illuminate\Container\Container;
use Tests\System\Utils\{
    CheckSecurityFakeKo,
    CheckSecurityFakeOk,
    ConfigurationFileBuilder,
    GitFilesFake,
    PhpFileBuilder
};
use Tests\SystemTestCase;

/*
    En todos los casos consideramos que están configuradas TODAS las herramientas. Consideramos 3 escenarios para cada herramienta:
    1. La herramienta termina sin detectar errores.
    2. La herramienta termina detectando algún error.
    3. La herramienta no llega a ejecutarse ya que cumple con las condiciones para ser saltada (este escenario no se cumple ni para
    check-security ni para phpstan que se ejecutarán siempre que hayan sido configuradas).

    Además, cuando se produce un fallo de sintaxis (Parallel-lint KO) las herramientas PhpStan y Mess Detector también son KO.

    Si aplicamos el algoritmo de todos los pares (https://pairwise.teremokgames.com/memw/) tenemos los sigueintes casos de prueba:
    |----------------------------------------------------------------------------------------------------|
    | nº Prueba |Check Security | Mess Detector | CPDetector | Code Sniffer |   Parallel-Lint |  PhpStan |
    |-----------|---------------|---------------| -----------|--------------|-----------------|----------|
    |    1      |       OK      |      OK       |    OK      |    OK        |       OK        |    OK    |
    |    2      |       OK      |      KO       |    KO      |    KO        |       KO        |    KO    |
    |    3      |       OK      |      exclude  |    exclude |    exclude   |       exclude   |    OK    |
    |    4      |       OK      |      OK       |    OK      |    OK        |       OK        |    KO    |
    |    5      |       KO      |      exclude  |    OK      |    KO        |       OK        |    KO    |
    |    6      |       KO      |      KO       |    OK      |    KO        |       exclude   |    KO    |
    |    7      |       KO      |      OK       |    KO      |    exclude   |       OK        |    OK    |
    |    8      |       OK      |      exclude  |    KO      |    KO        |       OK        |    OK    |
    |    9      |       OK      |      KO       |    KO      |    OK        |       OK        |    OK    |
    |    10     |       OK      |      KO       |    OK      |    OK        |       exclude   |    OK    |
    |    11     |       KO      |      OK       |    KO      |    KO        |       exclude   |    OK    |
    |    12     |       KO      |      KO       |    exclude |    OK        |       OK        |    KO    |
    |    13     |       KO      |      KO       |    KO      |    exclude   |       OK        |    KO    |
    |    14     |       OK      |      OK       |    KO      |    OK        |       exclude   |    KO    |
    |    15     |       OK      |      KO       |    OK      |    KO        |       OK        |    OK    |
    |    16     |       OK      |      OK       |    exclude |    KO        |       OK        |    OK    |
    |----------------------------------------------------------------------------------------------------|

 */

class ExecuteSmartStrategySystemTest extends SystemTestCase
{
    protected $configurationFile;

    protected function setUp(): void
    {
        $this->deleteDirStructure();

        $this->hiddenConsoleOutput();

        $this->createDirStructure();

        $this->configurationFile = new ConfigurationFileBuilder($this->getPath());
        $this->configurationFile->setOptions(['execution' => 'smart']);
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
    }

    /** @test */
    function execute_all_tools_without_errors()
    {
        $fileBuilder = new PhpFileBuilder('File');

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->build());

        $container = Container::getInstance();
        $container->bind(CheckSecurity::class, CheckSecurityFakeOk::class);
        $container->bind(GitFiles::class, GitFilesFake::class);
        $container->resolving(GitFilesFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php']);
        });

        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            //If something goes wrong I avoid throwing the exception because it hides the asserts
        }

        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertRegExp('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Your changes have been committed.', $this->getActualOutput());
    }

    /** @test */
    function checkSecurity_finish_OK_and_the_other_tools_finish_KO()
    {
        $fileBuilder = new PhpFileBuilder('File');

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcs', 'phpmd', 'parallel-lint', 'phpstan', 'phpcpd']));

        $container = Container::getInstance();
        $container->bind(CheckSecurity::class, CheckSecurityFakeOk::class);
        $container->bind(GitFiles::class, GitFilesFake::class);
        $container->resolving(GitFilesFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php']);
        });
        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            //Si algo sale mal evito lanzar la excepcion porque oculta los asserts
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasFailed('phpcbf');
        $this->assertToolHasFailed('phpmd');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolHasFailed('phpstan');
        $this->assertToolHasFailed('parallel-lint');
        $this->assertRegExp('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Your changes have not been committed. Please fix the errors and try again.', $this->getActualOutput());
    }

    /** @test */
    function checkSecurity_and_phpStan_finish_OK_and_other_tools_are_skipped()
    {
        $fileBuilder = new PhpFileBuilder('File');

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->build());

        $container = Container::getInstance();
        $container->bind(CheckSecurity::class, CheckSecurityFakeOk::class);
        $container->bind(GitFiles::class, GitFilesFake::class);
        $container->resolving(GitFilesFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([]);
        });

        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            //Si algo sale mal evito lanzar la excepcion porque oculta los asserts
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
        $this->assertToolDidNotRun('phpcbf');
        $this->assertToolDidNotRun('phpmd');
        $this->assertToolDidNotRun('phpcpd');
        $this->assertToolDidNotRun('parallel-lint');
        $this->assertRegExp('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Your changes have been committed.', $this->getActualOutput());
    }

    /** @test */
    function phpStan_finish_KO_and_other_tools_finish_OK()
    {
        $fileBuilder = new PhpFileBuilder('File');

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpstan']));

        $container = Container::getInstance();
        $container->bind(CheckSecurity::class, CheckSecurityFakeOk::class);
        $container->bind(GitFiles::class, GitFilesFake::class);
        $container->resolving(GitFilesFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php']);
        });

        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            //Si algo sale mal evito lanzar la excepcion porque oculta los asserts
        }

        $this->assertToolHasFailed('phpstan');
        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
    }

    /** @test */
    function CPDetector_and_ParallelLint_finish_OK_CheckSecurity_CodeSniffer_and_PhpStan_KO_and_MessDetector_is_skipped()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFile->setMessDetectorConfiguration([
            'paths' => [$this->getPath() . '/vendor'],
            'rules' => 'unusedcode',
            'exclude' => [$this->getPath() . '/src']
        ]);
        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpstan', 'phpcs', 'phpcpd']));

        $container = Container::getInstance();
        $container->bind(CheckSecurity::class, CheckSecurityFakeOk::class);
        $container->bind(GitFiles::class, GitFilesFake::class);
        $container->resolving(GitFilesFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php']);
        });

        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            //Si algo sale mal evito lanzar la excepcion porque oculta los asserts
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasFailed('phpstan');
        $this->assertToolHasFailed('phpcbf');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolDidNotRun('phpmd');
    }

    /** @test */
    function CPDetector_OK_CheckSecurity_MessDetector_CodeSniffer_and_PhpStan_KO_and_ParallelLint_is_skipped()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFile->setParallelLintConfiguration([
            'paths' => [$this->getPath() . '/vendor'],
            'exclude' => [$this->getPath() . '/src']
        ]);
        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpstan', 'phpcs', 'phpmd']));

        $container = Container::getInstance();
        $container->bind(CheckSecurity::class, CheckSecurityFakeKo::class);
        $container->bind(GitFiles::class, GitFilesFake::class);
        $container->resolving(GitFilesFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php']);
        });

        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            //Si algo sale mal evito lanzar la excepcion porque oculta los asserts
        }

        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasFailed('check-security');
        $this->assertToolHasFailed('phpstan');
        $this->assertToolHasFailed('phpcbf');
        $this->assertToolHasFailed('phpmd');
        $this->assertToolDidNotRun('parallel-lint');
    }

    /** @test */
    function CPDetector_and_CheckSecurity_KO_MessDetector_ParallelLint_and_PhpStan_OK_and_CodeSniffer_is_skipped()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFile->setPhpCSConfiguration([
            'paths' => [$this->getPath() . '/vendor'],
            'ignore' => [$this->getPath() . '/src']
        ]);
        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcpd']));

        $container = Container::getInstance();
        $container->bind(CheckSecurity::class, CheckSecurityFakeKo::class);
        $container->bind(GitFiles::class, GitFilesFake::class);
        $container->resolving(GitFilesFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php']);
        });

        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            //Si algo sale mal evito lanzar la excepcion porque oculta los asserts
        }

        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasFailed('check-security');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolDidNotRun('phpcbf');
    }
}
