<?php

namespace Tests\System;

use GitHooks\GitHooks;
use Illuminate\Container\Container;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\visitor\vfsStreamStructureVisitor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\SystemTestCase;
use Tests\VirtualFileSystemTrait;

class ExecuteFullStrategySystemTest extends TestCase
{
    use VirtualFileSystemTrait;

    //Allgoritmo de todos los pares:
    // Check Security   Code Sniffer    CPDectector Mess Detector   Parallellint    Stan
    // OK               OK              OK          OK              OK              OK
    // OK               KO              KO          KO              KO              KO
    // KO               KO              OK          KO              OK              KO
    // KO               OK              KO          OK              KO              OK

    /** @test */
    function it_execute_all_tools_and_pass_all_checks()
    {
        $this->markTestIncomplete("Pues eso, incompleto hasta que a las herramientas no se les pueda pasar el directorio contra el que se ejecutan");
        $fileBuilder = new PhpFileBuilder('File');

        $file = $fileBuilder->build();

        $structure = [
            'src' => [
                'File.php' => $file,
            ],
            'vendor' => [],
        ];

        $fileSystem = $this->createFileSystem($structure);

        $configurationFileBuilder = new ConfigurationFileBuilder($fileSystem);

        $configurationFile = $configurationFileBuilder->buildYalm();

        $structure = ['qa' => ['githooks.yml' => $configurationFile]];
        $this->createFileSystem($structure);

        $container = Container::getInstance();
        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getUrl('qa/githooks.yml')]);

        $githooks();

        //TODO tanto phpcs como phpmd están tirando contra ./src en lugar de contra el filesystem
        $this->assertStringContainsString('phpcpd - OK. Time:', $this->getActualOutput());
        $this->assertStringContainsString('phpstan - OK. Time:', $this->getActualOutput());
        // $this->assertStringContainsString('phpcs - OK. Time:', $this->getActualOutput());
        // $this->assertStringContainsString('phpmd - OK. Time:', $this->getActualOutput());
        // $this->assertStringContainsString('parallel-lint - OK. Time:', $this->getActualOutput());
        // $this->assertStringContainsString('check-security - OK. Time:', $this->getActualOutput());
    }
}
