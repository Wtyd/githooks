<?php

namespace Tests\System\Commands;

use phpmock\MockBuilder;
use phpmock\Mock as PhpmockMock;
use Tests\ConsoleTestCase;

class CleanHookCommandTest extends ConsoleTestCase
{

    protected $configurationFile;

    protected $mock;

    protected function setUp(): void
    {
        parent::setUp();

        mkdir($this->path . '/.git/hooks', 0777, true);

        $this->mock = $this->getMockRootDirectory();
        $this->mock->enable();
    }

    protected function tearDown(): void
    {
        $this->mock->disable();
        parent::tearDown();
    }

    /**
     * @return PhpmockMock
     */
    public function getMockRootDirectory(): PhpmockMock
    {
        $builder = new MockBuilder();
        $builder->setNamespace('Wtyd\GitHooks\App\Commands')
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
            ->containsStringInOutput('Hook pre-commit has been deleted')
            ->assertExitCode(0);
    }

    public function hooksProvider()
    {
        return [
            'applypatch-msg' => ['applypatch-msg'],
            'pre-applypatch' => ['pre-applypatch'],
            'post-applypatch' => ['post-applypatch'],
            'pre-commit' => ['pre-commit'],
            'pre-merge-commit' => ['pre-merge-commit'],
            'prepare-commit-msg' => ['prepare-commit-msg'],
            'commit-msg' => ['commit-msg'],
            'post-commit' => ['post-commit'],
            'pre-rebase' => ['pre-rebase'],
            'post-checkout' => ['post-checkout'],
            'post-merge' => ['post-merge'],
            'pre-push' => ['pre-push'],
            'pre-receive' => ['pre-receive'],
            'update' => ['update'],
            'proc-receive' => ['proc-receive'],
            'post-receive' => ['post-receive'],
            'post-update' => ['post-update'],
            'reference-transaction' => ['reference-transaction'],
            'push-to-checkout' => ['push-to-checkout'],
            'pre-auto-gc' => ['pre-auto-gc'],
            'post-rewrite' => ['post-rewrite'],
            'sendemail-validate' => ['sendemail-validate'],
            'fsmonitor-watchman' => ['fsmonitor-watchman'],
            'p4-changelist' => ['p4-changelist'],
            'p4-prepare-changelist' => ['p4-prepare-changelist'],
            'p4-post-changelist' => ['p4-post-changelist'],
            'p4-pre-submit' => ['p4-pre-submit'],
            'post-index-change' => ['post-index-change'],
        ];
    }

    /**
     * @test
     * @dataProvider hooksProvider
     */
    function it_deletes_the_hook_passed_as_argument($hook)
    {
        file_put_contents($this->getPath() . '/.git/hooks/' . $hook, '');

        $this->artisan("hook:clean $hook")
            ->containsStringInOutput("Hook $hook has been deleted")
            ->assertExitCode(0);
    }

    /** @test */
    function it_does_not_delete_a_hook_that_cannot_be_found()
    {
        $this->artisan('hook:clean pre-commit')
            ->containsStringInOutput('The hook pre-commit cannot be deleted because it cannot be found')
            ->assertExitCode(1);
    }

    /** @test */
    function it_does_not_delete_a_no_valid_hook()
    {
        $noSupportedHook = 'no-valid';

        $supportedHooks = [
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

        $supportedHooks2String = implode(', ', $supportedHooks);
        $this->artisan("hook:clean $noSupportedHook")
            ->containsStringInOutput("'$noSupportedHook' is not a valid git hook. Avaliable hooks are:")
            ->containsStringInOutput($supportedHooks2String)
            ->assertExitCode(1);

        $supportedHooksInOutput = explode(',', $this->getActualOutput());

        //Verifies that neither hook has been forgotten
        $this->assertCount(count($supportedHooks), $supportedHooksInOutput);
    }
}
