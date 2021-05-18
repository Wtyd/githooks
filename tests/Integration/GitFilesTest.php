<?php

namespace Tests\Integration;

use GitHooks\LoadTools\FastStrategy;
use GitHooks\Tools\CodeSniffer;
use GitHooks\Tools\CopyPasteDetector;
use GitHooks\Tools\MessDetector;
use GitHooks\Tools\ParallelLint;
use GitHooks\Tools\Stan;
use GitHooks\Tools\ToolsFactoy;
use GitHooks\Utils\GitFiles;
use Illuminate\Container\Container;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Utils\PhpFileBuilder;
use Tests\SystemTestCase;
use Tests\Utils\CheckSecurityFake;
use Tests\Utils\ConfigurationFileBuilder;

/**
 * Before executing this test suite after any changes, you must commit these changes
 * @group git
 */
class GitFilesTest extends SystemTestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Directory where this tests must be run. This directory is excluded from .gitignore.
     * This way, can be excuted 'git add' command.
     *
     * @var string
     */
    protected static $gitFilesPathTest = __DIR__ . '/../../testsDir/gitTests';

    protected function setUp(): void
    {
        parent::setUp();

        mkdir(self::$gitFilesPathTest);

        $this->container = Container::getInstance();
        $this->container->bind(GitFilesInterface::class, GitFiles::class);

        $this->configurationFileBuilder = new ConfigurationFileBuilder($this->deletePathPrefix(self::$gitFilesPathTest));
        $this->configurationFileBuilder->setOptions(['execution' => 'full']);
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
    }

    /** @test */
    function it_retrieve_modified_files()
    {
        $fileBuilder = new PhpFileBuilder('NewFile');

        $filename = self::$gitFilesPathTest . '/NewFile.php';
        file_put_contents($filename, $fileBuilder->build());

        shell_exec('git add ' . self::$gitFilesPathTest . '/NewFile.php');

        $gitFiles = $this->container->make(GitFiles::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([$this->deletePathPrefix($filename)], $modifiedFiles);

        $this->deleteDirStructure();

        shell_exec('git add ' . self::$gitFilesPathTest . '/NewFile.php');
    }

    /** @test */
    function it_retrieve_an_empty_array_when_there_are_no_modified_files()
    {
        $gitFiles = $this->container->make(GitFiles::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([], $modifiedFiles);
    }

    /** @test */
    function it_retrieve_an_empty_array_when_the_modified_files_there_are_no_added_to_the_git_stage()
    {
        $fileBuilder = new PhpFileBuilder('NewFile');

        file_put_contents(self::$gitFilesPathTest . '/NewFile.php', $fileBuilder->build());

        $gitFiles = $this->container->make(GitFiles::class);

        $modifiedFiles = $gitFiles->getModifiedFiles();

        $this->assertEquals([], $modifiedFiles);
    }

    function acelerableToolsProvider()
    {
        return [
            'Php Code Sniffer' => ['phpcs'],
            'Php Stan' => ['phpstan'],
            'Php Mess Detector' => ['phpmd'],
            'Parallel-Lint' => ['parallel-lint'],
        ];
    }

    /**
     * @test
     * @dataProvider acelerableToolsProvider
     */
    function it_modifies_the_key_path_of_configuration_file_when_fast_strategy_is_setted_according_to_the_modified_files($tool)
    {
        $this->configurationFileBuilder->setOptions(['execution' => 'fast'])->setTools([$tool]);

        $fileBuilder = new PhpFileBuilder('file1');

        mkdir(self::$gitFilesPathTest . '/app');
        file_put_contents(self::$gitFilesPathTest . '/app/file1.php', $fileBuilder->build());

        mkdir(self::$gitFilesPathTest . '/src');
        file_put_contents(self::$gitFilesPathTest . '/src/file2.php', $fileBuilder->build());

        mkdir(self::$gitFilesPathTest . '/otherPath');
        file_put_contents(self::$gitFilesPathTest . '/otherPath/file3.php', $fileBuilder->build());

        shell_exec(
            'git add ' . self::$gitFilesPathTest . '/app/file1.php ' . self::$gitFilesPathTest . '/src/file2.php ' . self::$gitFilesPathTest . '/otherPath/file3.php'
        );

        $ToolsFactorySpy = Mockery::spy(ToolsFactoy::class);
        $configurationFile = $this->configurationFileBuilder->buildArray();

        $gitFiles = $this->container->make(GitFiles::class);

        $fastStrategy = new FastStrategy($configurationFile, $gitFiles, $ToolsFactorySpy);

        $fastStrategy->getTools();

        $expectedFiles = [
            'testsDir/gitTests/src/file2.php',
        ];

        $configurationFile[$tool]['paths'] = $expectedFiles;
        try {
            $ToolsFactorySpy->shouldHaveReceived('__invoke', [[$tool], $configurationFile]);
        } catch (\Throwable $th) {
            $this->deleteDirStructure();
            shell_exec('git add .');
            throw $th;
        }
        $this->deleteDirStructure();
        shell_exec('git add .');
    }


    function it_modifies_key_path_for_configuration_file_for_acelerable_tools_when_fast_strategy_is_setted()
    {
        $this->configurationFileBuilder->setOptions(['execution' => 'fast']);

        $fileBuilder = new PhpFileBuilder('file1');

        mkdir(self::$gitFilesPathTest . '/app');
        file_put_contents(self::$gitFilesPathTest . '/app/file1.php', $fileBuilder->build());

        mkdir(self::$gitFilesPathTest . '/src');
        file_put_contents(self::$gitFilesPathTest . '/src/file2.php', $fileBuilder->build());

        mkdir(self::$gitFilesPathTest . '/otherPath');
        file_put_contents(self::$gitFilesPathTest . '/otherPath/file3.php', $fileBuilder->build());

        shell_exec(
            'git add ' . self::$gitFilesPathTest . '/app/file1.php ' . self::$gitFilesPathTest . '/src/file2.php ' . self::$gitFilesPathTest . '/otherPath/file3.php'
        );

        $configurationFile = $this->configurationFileBuilder->buildArray();

        $gitFiles = $this->container->make(GitFiles::class);

        // Build fastStrategy with original configurationFile
        $fastStrategy = new FastStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $expectedFiles = [
            'testsDir/gitTests/src/file2.php',
        ];

        // ConfigurationFile is modified by FastStrategy only for the acelerable tools
        $configurationFile['phpcs']['paths'] = $expectedFiles;
        $configurationFile['parallel-lint']['paths'] = $expectedFiles;
        $configurationFile['phpmd']['paths'] = $expectedFiles;
        $configurationFile['phpstan']['paths'] = $expectedFiles;

        $expectedTools = [
            'phpcs' => new CodeSniffer($configurationFile),
            'parallel-lint' => new ParallelLint($configurationFile),
            'phpmd' => new MessDetector($configurationFile),
            'phpcpd' => new CopyPasteDetector($configurationFile),
            'phpstan' => new Stan($configurationFile),
            'check-security' => new CheckSecurityFake($configurationFile),
        ];

        $this->assertEquals($expectedTools, $fastStrategy->getTools());

        $this->deleteDirStructure();
        shell_exec('git add .');
    }

    /**
     * @param string $path Absolute path. For example: /var/www/html/githooks/tests/NewFile.php
     *
     * @return string Only the relative path of the file to root project. For example: tests/NewFile.php
     */
    public function deletePathPrefix(string $path): string
    {
        $path = explode('testsDir/', $path);

        return 'testsDir/' . $path[1];
    }
}
