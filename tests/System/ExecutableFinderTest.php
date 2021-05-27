<?php

namespace Tests\System;

use Wtyd\GitHooks\GitHooks;
use Tests\SystemTestCase;
use Tests\Utils\CheckSecurityFake;
use Tests\Utils\PhpFileBuilder;

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
        parent::setUp();

        $this->configurationFileBuilder->setOptions(['execution' => 'full']);
    }

    /** @test */
    function it_execute_all_tools_and_pass_all_checks()
    {
        $fileBuilder = new PhpFileBuilder('File');

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->build());

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $githooks = $this->container->makeWith(GitHooks::class);

        $githooks();

        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully(PhpFileBuilder::PHPMD);
        $this->assertToolHasBeenExecutedSuccessfully(PhpFileBuilder::PHPCPD);
        $this->assertToolHasBeenExecutedSuccessfully(PhpFileBuilder::PHPSTAN);
        $this->assertToolHasBeenExecutedSuccessfully(PhpFileBuilder::PARALLEL_LINT);
        $this->assertMatchesRegularExpression('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Your changes have been committed.', $this->getActualOutput());
    }
}
