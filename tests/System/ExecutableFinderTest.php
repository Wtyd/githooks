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
 * 3. All tools are downloaded as phar in project root path.
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

        $this->container = Container::getInstance();
        $this->mockPathGitHooksConfigurationFile();

        $this->configurationFile = new ConfigurationFileBuilder($this->getPath());
        $this->configurationFile->setOptions(['execution' => 'full']);
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

        $this->container->bind(CheckSecurity::class, CheckSecurityFakeOk::class);
        $githooks = $this->container->makeWith(GitHooks::class);

        $githooks();

        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully(PhpFileBuilder::PHPMD);
        $this->assertToolHasBeenExecutedSuccessfully(PhpFileBuilder::PHPCPD);
        $this->assertToolHasBeenExecutedSuccessfully(PhpFileBuilder::PHPSTAN);
        $this->assertToolHasBeenExecutedSuccessfully(PhpFileBuilder::PARALLEL_LINT);
        $assertMatchesRegularExpression = $this->assertMatchesRegularExpression;
        $this->$assertMatchesRegularExpression('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Your changes have been committed.', $this->getActualOutput());
    }
}
