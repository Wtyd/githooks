<?php

namespace Tests\System\Commands;

use phpmock\MockBuilder;
use phpmock\Mock as PhpmockMock;
use Tests\Artisan\ConsoleTestCase;

class CleanHookCommandTest extends ConsoleTestCase
{

    protected $configurationFile;

    protected $artisan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteDirStructure();

        mkdir($this->path . '/.git/hooks', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
    }

    /**
     * @return PhpmockMock
     */
    public function getMockRootDirectory(): PhpmockMock
    {
        $builder = new MockBuilder();
        $builder->setNamespace('GitHooks\Commands')
            ->setName('getcwd')
            ->setFunction(
                function () {
                    return $this->getPath();
                }
            );

        return $builder->build();
    }

    /** @test */
    function it_deletes_the_precommit_hook_as_default()
    {
        file_put_contents($this->getPath() . '/.git/hooks/pre-commit', '');

        $mock = $this->getMockRootDirectory();
        $mock->enable();

        $this->artisan('hook:clean')
            ->containsStringInOutput('Hook pre-commit has been deleted');

        $mock->disable();
    }

    /**
     * @test
     * //FIXME Phpunit dataProviders don't work in this tests
     */
    function it_deletes_the_hook_passed_as_argument()
    {
        $hooks = [
            'applypatch-msg' => 'applypatch-msg',
            'commit-msg' => 'commit-msg',
            'fsmonitor-watchman' => 'fsmonitor-watchman',
            'post-update' => 'post-update',
            'pre-applypatch' => 'pre-applypatch',
            'pre-commit' => 'pre-commit',
            'prepare-commit-msg' => 'prepare-commit-msg',
            'pre-push' => 'pre-push',
            'pre-rebase' => 'pre-rebase',
            'pre-receive' => 'pre-receive',
            'update' => 'update',
        ];

        $mock = $this->getMockRootDirectory();
        $mock->enable();

        foreach ($hooks as $hook) {
            file_put_contents($this->getPath() . '/.git/hooks/' . $hook, '');

            $this->artisan("hook:clean $hook")
                ->containsStringInOutput("Hook $hook has been deleted");
        }


        $mock->disable();
    }

    /** @test */
    function it_does_not_delete_a_hook_that_cannot_be_found()
    {
        $mock = $this->getMockRootDirectory();
        $mock->enable();

        $this->artisan('hook:clean pre-commit')
            ->containsStringInOutput('The hook pre-commit cannot be deleted because it cannot be found');

        $mock->disable();
    }

    /** @test */
    function it_does_not_delete_a_no_valid_hook()
    {
        $noSupportedHook = 'no-valid';
        $mock = $this->getMockRootDirectory();
        $mock->enable();

        $this->artisan("hook:clean $noSupportedHook")
            ->containsStringInOutput("'$noSupportedHook' is not a valid git hook. Avaliable hooks are:")
            ->containsStringInOutput('applypatch-msg, commit-msg, fsmonitor-watchman, post-update, pre-applypatch, pre-commit, prepare-commit-msg, pre-push, pre-rebase, pre-receive, update');

        $mock->disable();
    }
}
