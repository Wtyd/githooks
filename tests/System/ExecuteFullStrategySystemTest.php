<?php

namespace Tests\System;

use GitHooks\GitHooks;
use GitHooks\Tools\CheckSecurity;
use Illuminate\Container\Container;
use Tests\System\Utils\{
    CheckSecurityFakeKo,
    CheckSecurityFakeOk,
    ConfigurationFileBuilder,
    PhpFileBuilder
};
use Tests\SystemTestCase;

/**
 * No se tiene encuenta check-security ya que depende de la configuración de composer.json y de que no se hayan encontrado
 * vulnerabilidades en las librerías configuradas.
 */
class ExecuteFullStrategySystemTest extends SystemTestCase
{
    //Allgoritmo de todos los pares:
    // Check Security   Mess Detector   Code Sniffer  Parallellint  CPDectector       Stan
    // OK               OK              OK          OK              OK              OK      --> it_execute_all_tools_and_pass_all_checks
    // OK               KO              KO          KO              KO              KO      --> it_execute_all_tools_all_ko
    // KO               KO              OK          KO              OK              KO      --> it_execute_all_tools_codesniffer_and_copypastedetector_ok
    // KO               OK              KO          OK              KO              OK      --> it_execute_all_tools_codesniffer_and_copypastedetector_ko

    protected function setUp(): void
    {
        $this->deleteDirStructure();

        $this->createDirStructure();

        $this->hiddenConsoleOutput();
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
    }

    /** @test */
    function it_execute_all_tools_and_pass_all_checks()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $configurationFileBuilder = new ConfigurationFileBuilder($this->getPath());

        file_put_contents($this->getPath() . '/githooks.yml', $configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->build());

        $container = Container::getInstance();
        $container->bind(CheckSecurity::class, CheckSecurityFakeOk::class);
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
        $this->assertRegExp('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Your changes have been committed.', $this->getActualOutput());
    }

    /** @test */
    function it_execute_all_tools_all_ko()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $configurationFileBuilder = new ConfigurationFileBuilder($this->getPath());

        file_put_contents($this->getPath() . '/githooks.yml', $configurationFileBuilder->buildYalm());


        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors([
            PhpFileBuilder::PHPMD, PhpFileBuilder::PHPCS, PhpFileBuilder::PHPCS_NO_FIXABLE, PhpFileBuilder::PHPSTAN, PhpFileBuilder::PARALLEL_LINT, PhpFileBuilder::PHPCPD
        ]));

        $container = Container::getInstance();
        $container->bind(CheckSecurity::class, CheckSecurityFakeOk::class);
        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            //Si algo sale mal evito lanzar la excepcion porque oculta los asserts
        }

        $this->assertToolHasFailed('phpcbf');
        $this->assertToolHasFailed('phpmd');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolHasFailed('phpstan');
        $this->assertToolHasFailed('parallel-lint');
        $this->assertRegExp('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Your changes have not been committed. Please fix the errors and try again.', $this->getActualOutput());
    }

    /** @test */
    function it_execute_all_tools_codesniffer_and_copypastedetector_ok()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $configurationFileBuilder = new ConfigurationFileBuilder($this->getPath());

        file_put_contents($this->getPath() . '/githooks.yml', $configurationFileBuilder->buildYalm());


        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors([
            PhpFileBuilder::PARALLEL_LINT, PhpFileBuilder::PHPMD, PhpFileBuilder::PHPSTAN,
        ]));

        $container = Container::getInstance();
        $container->bind(CheckSecurity::class, CheckSecurityFakeKo::class);
        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            //Si algo sale mal evito lanzar la excepcion porque oculta los asserts
        }

        $this->assertToolHasFailed(PhpFileBuilder::PHPSTAN);
        $this->assertToolHasFailed(PhpFileBuilder::PHPMD);
        $this->assertToolHasFailed(PhpFileBuilder::PARALLEL_LINT);
        $this->assertToolHasBeenExecutedSuccessfully(PhpFileBuilder::PHPCPD);
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertRegExp('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Your changes have not been committed. Please fix the errors and try again.', $this->getActualOutput());
    }

    /** @test */
    function it_execute_all_tools_codesniffer_and_copypastedetector_ko()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $configurationFileBuilder = new ConfigurationFileBuilder($this->getPath());

        file_put_contents($this->getPath() . '/githooks.yml', $configurationFileBuilder->buildYalm());


        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors([
            PhpFileBuilder::PHPCS_NO_FIXABLE, PhpFileBuilder::PHPCS, PhpFileBuilder::PHPCPD
        ]));
        //file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors());

        $container = Container::getInstance();
        $container->bind(CheckSecurity::class, CheckSecurityFakeKo::class);
        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            //Si algo sale mal evito lanzar la excepcion porque oculta los asserts
        }

        $this->assertToolHasBeenExecutedSuccessfully(PhpFileBuilder::PHPSTAN);
        $this->assertToolHasBeenExecutedSuccessfully(PhpFileBuilder::PHPMD);
        $this->assertToolHasBeenExecutedSuccessfully(PhpFileBuilder::PARALLEL_LINT);
        $this->assertToolHasFailed('phpcbf');
        $this->assertToolHasFailed(PhpFileBuilder::PHPCPD);
        $this->assertRegExp('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Your changes have not been committed. Please fix the errors and try again.', $this->getActualOutput());
    }
}
