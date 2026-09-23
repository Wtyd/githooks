<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;
use Tests\Utils\Traits\GitSandboxTrait;

/**
 * `githooks hook` over a v2 configuration used to install a script pointing at
 * `hook:run`, the v3 entry point, which rejects a v2 file outright. The command
 * reported "Hook pre-commit created" and exited 0, and from then on every
 * commit in that repository was blocked by
 * `hook:run requires v3 configuration format`.
 *
 * The regression came in with the v3 hook install (7a68bf64): before it, the
 * generated script ran `tool all`, the v2 entry point. 3.8 fixed the sibling
 * path — the one that bakes `--config` into the v3 scripts — and left this one.
 *
 * Required as @group release because both halves only meet in the distributed
 * binary: the script this command writes is executed by git, through the very
 * same `.phar`, on the user's machine. Whether the two agree on an entry point
 * is not observable anywhere else.
 *
 * Not tagged `@group git`: the sandbox lives under the system temp directory
 * and never touches this repository, and the tag would take the class out of
 * the release pipeline, which runs `--group release`.
 *
 * @group release
 */
class LegacyHookEntryPointReleaseTest extends ReleaseTestCase
{
    use GitSandboxTrait;

    private string $binary;

    protected function setUp(): void
    {
        parent::setUp();

        // Absolute, because the sandbox changes the working directory.
        $this->binary = (string) realpath($this->githooks);

        $this->setUpGitSandbox();

        // The generated script calls `php vendor/bin/githooks`, so the sandbox
        // needs the binary where a real project would have it.
        mkdir('vendor/bin', 0755, true);
        copy($this->binary, 'vendor/bin/githooks');
        chmod('vendor/bin/githooks', 0755);

        mkdir('src', 0755, true);
        file_put_contents('src/File.php', "<?php\n\nclass Subject\n{\n}\n");
    }

    protected function tearDown(): void
    {
        $this->tearDownGitSandbox();

        parent::tearDown();
    }

    private function writeV2Config(): void
    {
        $config = <<<'PHP'
<?php
return [
    'Options' => ['execution' => 'full', 'processes' => 1],
    'Tools'   => ['parallel-lint'],
    'parallel-lint' => ['paths' => ['src']],
];
PHP;
        file_put_contents('githooks.php', $config);
    }

    /** @test */
    public function a_hook_installed_over_a_v2_config_runs_the_v2_entry_point(): void
    {
        $this->writeV2Config();

        exec("$this->binary hook 2>&1", $installOutput, $installExit);

        $this->assertSame(0, $installExit, implode("\n", $installOutput));
        $this->assertStringContainsString(
            'still in v2 format',
            implode("\n", $installOutput),
            'the install must say the configuration has not been migrated'
        );

        $script = (string) file_get_contents('.git/hooks/pre-commit');
        $this->assertStringContainsString('tool all', $script);
        $this->assertStringNotContainsString('hook:run', $script, 'hook:run rejects a v2 configuration');
    }

    /**
     * @test
     *
     * End to end, where the old behaviour cannot get lucky: the hook is fired
     * exactly as git fires it. What matters is not the exit code — the sandbox
     * has no analyzers installed, so the run fails either way — but that the
     * failure is the tool's and not the runtime refusing to read the file.
     */
    public function the_installed_hook_does_not_reject_the_configuration_it_was_installed_from(): void
    {
        $this->writeV2Config();
        exec("$this->binary hook 2>&1", $installOutput, $installExit);
        $this->assertSame(0, $installExit, implode("\n", $installOutput));

        exec('sh .git/hooks/pre-commit 2>&1', $hookOutput, $hookExit);
        $rendered = implode("\n", $hookOutput);

        $this->assertStringNotContainsString(
            'requires v3 configuration format',
            $rendered,
            'the hook must not refuse the very configuration it was installed from'
        );
        // The v2 entry point announces itself; reaching that line proves the
        // configuration was read rather than rejected.
        $this->assertStringContainsString("The 'tool' command is deprecated", $rendered);
    }
}
