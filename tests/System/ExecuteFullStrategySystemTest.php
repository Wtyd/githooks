<?php

namespace Tests\System;

use GitHooks\GitHooks;
use Illuminate\Container\Container;
use Tests\SystemTestCase;

/**
 * No se tiene encuenta check-security ya que depende de la configuración de composer.json y de que no se hayan encontrado
 * vulnerabilidades en las librerías configuradas.
 */
class ExecuteFullStrategySystemTest extends SystemTestCase
{
    //Allgoritmo de todos los pares:
    // Check Security   Code Sniffer    CPDectector Mess Detector   Parallellint    Stan
    // OK               OK              OK          OK              OK              OK
    // OK               KO              KO          KO              KO              KO
    // KO               KO              OK          KO              OK              KO
    // KO               OK              KO          OK              KO              OK

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
        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            //Si algo sale mal evito lanzar la excepcion porque oculta los asserts
        }

        $regExp = '(\.phar)? - OK\. Time: \d+\.\d{2}'; //phpcbf[.phar] - OK. Time: 0.18
        $this->assertRegExp("%phpcbf$regExp%", $this->getActualOutput());
        $this->assertRegExp("%phpmd$regExp%", $this->getActualOutput());
        $this->assertRegExp("%phpcpd$regExp%", $this->getActualOutput());
        $this->assertRegExp("%phpstan$regExp%", $this->getActualOutput());
        $this->assertRegExp("%parallel-lint$regExp%", $this->getActualOutput());
        $this->assertRegExp('%Tiempo total de ejecución = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Tus cambios se han commiteado.', $this->getActualOutput());
    }

    /** @test */
    function it_otro_caso()
    {
        $this->markTestIncomplete('Hay que corregir PHPMD cuando hay un error de sintaxis y PHPSTAN que no falla nunca');
        $fileBuilder = new PhpFileBuilder('File');

        $configurationFileBuilder = new ConfigurationFileBuilder($this->getPath());

        // $configurationFile = $configurationFileBuilder->buildYalm();
        file_put_contents($this->getPath() . '/githooks.yml', $configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors([
            PhpFileBuilder::PHPMD, PhpFileBuilder::PHPCS, PhpFileBuilder::PHPCS_NO_FIXABLE, PhpFileBuilder::PHPSTAN, PhpFileBuilder::PARALLEL_LINT,
        ]));

        $container = Container::getInstance();
        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        $githooks();

        // $this->assertStringContainsString('phpcpd - OK. Time:', $this->getActualOutput());
        // $this->assertStringContainsString('phpstan - OK. Time:', $this->getActualOutput());
        // $this->assertStringContainsString('phpcs - OK. Time:', $this->getActualOutput());
        // $this->assertStringContainsString('phpmd - OK. Time:', $this->getActualOutput());
        // $this->assertStringContainsString('parallel-lint - OK. Time:', $this->getActualOutput());
        // $this->assertStringContainsString('check-security - OK. Time:', $this->getActualOutput());
    }

    /** @test */
    function prueba_regex()
    {
        $this->markTestSkipped('Test para probar las expresiones regulares');
        $regExp = '%(phpcbf|phpmd|phpcpd|phpstan|parallel-lint)(\.phar)? - OK\. Time: \d+\.\d{2}%';
        echo "✔️ phpcbf - OK. Time: 0.18
        ✔️ phpmd - OK. Time: 0.17
        ✔️ phpcpd - OK. Time: 0.07
        ✔️ phpstan - OK. Time: 0.58
        ✔️ parallel-lint - OK. Time: 0.58

  Tiempo total de ejecución = 1.01 sec
Tus cambios se han commiteado.";
    //TODO conseguire que sea 5 repeteciiones de o phpcs o phpstan o phpmd... etc
    //https://regex101.com/
        $this->assertRegExp('%phpcbf(\.phar)? - OK\. Time: \d\.\d{2}%', $this->getActualOutput());
        $this->assertRegExp('%phpmd(\.phar)? - OK\. Time: \d\.\d{2}%', $this->getActualOutput());
        $this->assertRegExp('%phpcpd(\.phar)? - OK\. Time: \d\.\d{2}%', $this->getActualOutput());
        $this->assertRegExp('%phpstan(\.phar)? - OK\. Time: \d\.\d{2}%', $this->getActualOutput());
    }
}
