<?php

namespace Tests\System\Commands;

use phpmock\MockBuilder;
use phpmock\Mock as PhpmockMock;
use Tests\Artisan\ConsoleTestCase;

class CleanHookCommandTest extends ConsoleTestCase
{

    protected $configurationFile;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteDirStructure();

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

        $this->artisan('hook:clean')
            ->containsStringInOutput('Hook pre-commit has been deleted');
    }

    /**
     * @test
     * //FIXME Phpunit dataProviders don't work in this tests
     */
    function it_deletes_the_hook_passed_as_argument()
    {
        foreach ($this->supportedHooks as $hook) {
            file_put_contents($this->getPath() . '/.git/hooks/' . $hook, '');

            $this->artisan("hook:clean $hook")
                ->containsStringInOutput("Hook $hook has been deleted");
        }
    }

    /** @test */
    function it_does_not_delete_a_hook_that_cannot_be_found()
    {
        $this->artisan('hook:clean pre-commit')
            ->containsStringInOutput('The hook pre-commit cannot be deleted because it cannot be found');
    }

    /** @test */
    function it_does_not_delete_a_no_valid_hook()
    {
        $noSupportedHook = 'no-valid';

        $supportedHooks2String = implode(', ', $this->supportedHooks);
        $this->artisan("hook:clean $noSupportedHook")
            ->containsStringInOutput("'$noSupportedHook' is not a valid git hook. Avaliable hooks are:")
            ->containsStringInOutput($supportedHooks2String);
    }
}
