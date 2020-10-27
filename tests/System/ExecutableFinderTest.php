<?php

namespace Tests\System;

use GitHooks\GitHooks;
use GitHooks\Tools\CheckSecurity;
use Illuminate\Container\Container;
use Tests\System\Utils\{
    CheckSecurityFakeOk,
    ConfigurationFileBuilder,
    PhpFileBuilder
};
use Tests\SystemTestCase;

/**
 * This test is exluded from automated test suite. Only must by runned on pipeline and on isolation. It run all tools and test where is the executable.
 * For it, three scenarios must be considered:
 * 1. All tools are installed globally.
 * 2. All tools are installed how project dependencies.
 * 3. All toolas are downloaded as phar in project root path.
 * The test must be run three times, once per scenario. This scenario is configured in pipeline.
 */
class ExecutableFinderTest extends SystemTestCase
{
    protected $configurationFile;

    protected function setUp(): void
    {
        $this->deleteDirStructure();

        $this->hiddenConsoleOutput();

        $this->createDirStructure();

        $this->configurationFile = new ConfigurationFileBuilder($this->getPath());
        $this->configurationFile->setOptions(['execution' => 'fast']);
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
            //Si algo sale mal evito lanzar la excepcion porque oculta los asserts
        }


        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertRegExp('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Your changes have been committed.', $this->getActualOutput());
    }
}
