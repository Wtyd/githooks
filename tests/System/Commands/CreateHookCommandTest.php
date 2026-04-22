<?php

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Utils\Storage;

/**
 * Testing Wtyd\GitHooks\App\Commands\CreateHookCommand;
 */
class CreateHookCommandTest extends SystemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->copyDefaultPrecommitToTestDirectory();
        Storage::makeDirectory('.git/hooks', 0777, true);
    }

    /**
     * Copy the 'hooks' directory of the application with de default script for the hooks ('hooks/precommit.php') to the root of the temporal
     * directory for tests.
     *
     * @return void
     */
    protected function copyDefaultPrecommitToTestDirectory()
    {
        Storage::makeDirectory('/hooks', 0777, true);
        shell_exec('cp -r hooks ' . $this->path);
    }

    /** @test */
    function it_creates_default_script_for_precommit_when_is_called_without_arguments()
    {
        $this->artisan('hook --legacy')
            ->containsStringInOutput('Hook pre-commit created')
            ->assertExitCode(0);

        $this->assertFileExists($this->path . '/.git/hooks/pre-commit');
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
    function it_creates_default_script_in_the_hook_passed_as_argument($hook)
    {
        $this->artisan("hook $hook --legacy")
            ->containsStringInOutput("Hook $hook created")
            ->assertExitCode(0);

        $this->assertFileExists($this->path . "/.git/hooks/$hook");
    }

    /**
     * @test
     * Only is tested pre-push hook but it could be any hook.
     */
    function it_sets_a_custom_script_as_some_hook()
    {
        $hookContent = 'my custom script';
        $scriptFile = 'MyScript.php';
        Storage::put($scriptFile, $hookContent);

        $this->artisan("hook pre-push $scriptFile")
            ->containsStringInOutput("Hook pre-push created")
            ->assertExitCode(0);

        $this->assertFileExists($this->path . "/.git/hooks/pre-push", $scriptFile);
    }

    /** @test */
    function it_shows_an_error_message_when_is_setted_a_custom_script_without_specifying_the_hook()
    {
        $hookContent = 'my custom script';
        $scriptFilePath = $this->path . '/MyScript.php';
        file_put_contents($scriptFilePath, $hookContent);

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
        $this->artisan("hook $scriptFilePath")
            ->containsStringInOutput("'$scriptFilePath' is not a valid git hook. Available hooks are:")
            ->containsStringInOutput($supportedHooks2String)
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->path . "/.git/hooks/pre-commit", $scriptFilePath);
    }

    /** @test */
    function legacy_mode_overwrites_existing_hook_file()
    {
        Storage::put('.git/hooks/pre-commit', 'stale content');

        $this->artisan('hook --legacy')
            ->containsStringInOutput('Hook pre-commit created')
            ->assertExitCode(0);

        $this->assertFileExists($this->path . '/.git/hooks/pre-commit');
        $this->assertStringNotEqualsFile($this->path . '/.git/hooks/pre-commit', 'stale content');
    }

    /** @test */
    function reports_error_when_custom_script_file_does_not_exist()
    {
        $this->artisan('hook pre-push missing-script.php')
            ->containsStringInOutput('missing-script.php file not found')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->path . '/.git/hooks/pre-push');
    }

    /** @test */
    function v3_mode_warns_when_hooks_section_is_empty()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks([])
            ->buildInFileSystem();

        $configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->artisan("hook pre-commit --config=$configPath")
            ->containsStringInOutput('No hooks defined in configuration')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($this->path . '/.git/hooks/pre-commit');
    }

    /** @test */
    function it_shows_an_error_message_when_is_setted_an_invalid_hook()
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
        $this->artisan("hook $noSupportedHook --legacy")
            ->containsStringInOutput("'$noSupportedHook' is not a valid git hook. Available hooks are:")
            ->containsStringInOutput($supportedHooks2String)
            ->assertExitCode(1);

        $supportedHooksInOutput = explode(',', $this->getActualOutput());

        //Verifies that neither hook has been forgotten
        $this->assertCount(count($supportedHooks), $supportedHooksInOutput);
    }
}
