<?php

namespace Tests\System\Commands;

use phpmock\MockBuilder;
use phpmock\Mock as PhpmockMock;
use Tests\Artisan\ConsoleTestCase;

class CreateHookCommandTest extends ConsoleTestCase
{
    protected $mock;

    protected $supportedHooks = [
        'applypatch-msg',
        'pre-applypatch',
        'post-applypatch',
        'pre-commit',
        'pre-merge-commit',
        'prepare-commit-msg',
        'commit-msg',
        'post-commit',
        'pre-rebase',
        'post-checkout',
        'post-merge',
        'pre-push',
        'pre-receive',
        'update',
        'proc-receive',
        'post-receive',
        'post-update',
        'reference-transaction',
        'push-to-checkout',
        'pre-auto-gc',
        'post-rewrite',
        'sendemail-validate',
        'fsmonitor-watchman',
        'p4-changelist',
        'p4-prepare-changelist',
        'p4-post-changelist',
        'p4-pre-submit',
        'post-index-change',
    ];

    /**
     * Creates the temporal filesystem structure for the tests and mocks the 'getcwd' method for return this path.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteDirStructure();

        $this->copyDefaultPrecommitToTestDirectory();
        mkdir($this->path . '/.git/hooks', 0777, true);

        $this->mock = $this->getMockRootDirectory();
        $this->mock->enable();
    }

    protected function tearDown(): void
    {
        $this->mock->disable();
        $this->deleteDirStructure();
    }

    /**
     * Copy the 'hooks' directory of the application with de default script for the hooks ('hooks/precommit.php') to the root of the temporal
     * directory for tests.
     *
     * @return void
     */
    protected function copyDefaultPrecommitToTestDirectory()
    {
        mkdir($this->path . '/hooks', 0777, true);
        shell_exec('cp -r hooks ' . $this->path);
    }

    /**
     * Mocks the 'getcwd' method for return the root of the filesystem for this tests.
     *
     * @return PhpmockMock
     */
    public function getMockRootDirectory(): PhpmockMock
    {
        $builder = new MockBuilder();
        $builder->setNamespace('GitHooks\Commands')
            ->setName('getcwd')
            ->setFunction(
                function () {
                    return $this->path;
                }
            );

        return $builder->build();
    }

    /** @test */
    function it_creates_default_script_for_precommit_when_is_called_without_arguments()
    {
        $this->artisan('hook')
            ->containsStringInOutput('Hook pre-commit created');

        $this->assertFileExists($this->path . '/.git/hooks/pre-commit', file_get_contents('hooks/pre-commit.php'));
    }

    /**
     * @test
     * //FIXME Phpunit dataProviders don't work in this tests
     */
    function it_creates_default_script_in_the_hook_passed_as_argument()
    {
        foreach ($this->supportedHooks as $hook) {
            $this->artisan("hook $hook")
                ->containsStringInOutput("Hook $hook created");

            $this->assertFileExists($this->path . "/.git/hooks/$hook", file_get_contents('hooks/pre-commit.php'));
        }
    }

    /**
     * @test
     * Only is tested pre-push hook but it could be any hook.
     */
    function it_sets_a_custom_script_as_some_hook()
    {
        $hookContent = 'my custom script';
        $scriptFilePath = $this->path . '/MyScript.php';
        file_put_contents($scriptFilePath, $hookContent);

        $this->artisan("hook pre-push $scriptFilePath")
            ->containsStringInOutput("Hook pre-push created");

        $this->assertFileExists($this->path . "/.git/hooks/pre-push", $scriptFilePath);
    }

    /** @test */
    function it_shows_an_error_message_when_is_setted_a_custom_script_without_specifying_the_hook()
    {
        $hookContent = 'my custom script';
        $scriptFilePath = $this->path . '/MyScript.php';
        file_put_contents($scriptFilePath, $hookContent);

        $supportedHooks2String = implode(', ', $this->supportedHooks);
        $this->artisan("hook $scriptFilePath")
            ->containsStringInOutput("'$scriptFilePath' is not a valid git hook. Avaliable hooks are:")
            ->containsStringInOutput($supportedHooks2String);

        $assertFileDoesNotExist = $this->assertFileDoesNotExist;
        $this->$assertFileDoesNotExist($this->path . "/.git/hooks/pre-commit", $scriptFilePath);
    }

    /** @test */
    function it_shows_an_error_message_when_is_setted_an_invalid_hook()
    {
        $noSupportedHook = 'no-valid';

        $supportedHooks2String = implode(', ', $this->supportedHooks);
        $this->artisan("hook $noSupportedHook")
            ->containsStringInOutput("'$noSupportedHook' is not a valid git hook. Avaliable hooks are:")
            ->containsStringInOutput($supportedHooks2String);
    }
}
