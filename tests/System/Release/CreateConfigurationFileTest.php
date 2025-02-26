<?php

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class CreateConfigurationFileTest extends ReleaseTestCase
{
    public function tearDown(): void
    {
        shell_exec("git checkout -- 'qa/*'");
    }
    /** @test */
    function it_creates_the_configuration_file_and_returns_exit_code_0()
    {
        $this->deleteDirStructure('vendor/wtyd');

        $githooksPhp = 'qa/githooks.php';
        unlink($githooksPhp);
        $githooksYml = 'qa/githooks.yml';
        unlink($githooksYml);
        // mkdir('vendor/wtyd/githooks/qa/', 0777, true);
        // file_put_contents(
        //     'vendor/wtyd/githooks/qa/githooks.dist.php',
        //     $this->configurationFileBuilder->buildPhp()
        // );
        // Crea el fichero de configuración en php en el directorio

        $this->configurationFileBuilder->buildInFileSystem('vendor/wtyd/githooks/qa/', true);
        // dd('prueba');
        passthru("$this->githooks conf:init", $exitCode);

        $this->assertStringContainsString('Configuration file githooks.php has been created in root path', $this->getActualOutput());
        $this->assertEquals(0, $exitCode);
        $this->assertFileExists('githooks.php');

        $this->deleteDirStructure('vendor/wtyd');
    }

    // TODO: Add test to check if the file already exists
}
