<?php

use GitHooks\Configuration;
use GitHooks\LoadTools\SmartStrategy;
use GitHooks\Tools\ToolsFactoy;
use GitHooks\Utils\GitFiles;
use PHPUnit\Framework\TestCase;
use Tests\VirtualFileSystemTrait;

class SmartStrategyCanBeActivatedFromTheConfiguractionFileTest extends TestCase
{
    use VirtualFileSystemTrait;
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /** @test*/
    function it_can_skip_all_tools_except_phpstan()
    {
        $this->markTestIncomplete();
        $fileSystem = [
            'file.php',
            'qa' => [
                'githooks.yml' => //Por problemas de tabulación al leer yml debe de estar así
        'Options:
    - smartExecution: true
Tools: 
    #- phpstan
    #- dependencyVulnerabilities
    - parallelLint
    #- phpcs
    #- phpmd
    #- phpcpd

parallelLint:
- exclude: [app]
phpcs:
- ignore: [app]',
                'otroFichero.php',
            ]
        ];
        $this->createFileSystem($fileSystem);

        $modifiedFiles = ['app/file1.php', 'app/file2.php'];
        $gitFiles = Mockery::mock(GitFiles::class);
        $gitFiles->shouldReceive('getModifiedFiles')->andReturn($modifiedFiles);
        $gitFiles->shouldReceive('isComposerModified')->andReturn(false);

        $conf = new Configuration();

        $smartStrategy = new SmartStrategy($conf->readFile($this->getFile('qa/githooks.yml')), $gitFiles, new ToolsFactoy());

        // var_dump($conf->readFile($this->getFile('qa/githooks.yml')));
        var_dump($smartStrategy->getTools());
        exit;
        $this->assertCount(1, $smartStrategy->getTools());
    }
}
