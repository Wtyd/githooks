<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;
use Tests\Utils\Traits\GitSandboxTrait;

/**
 * 3.7 — the auto re-stage that follows a fix must cover the files the job
 * rewrote, not the whole index. `git add` was run over every staged path, so a
 * fixer sweeping through a pre-commit hook also committed edits the author had
 * deliberately left unstaged in unrelated files.
 *
 * Required as @group release because the re-stage runs inside the distributed
 * binary during a real hook: only the compiled `.phar` proves the scope is the
 * one shipped.
 *
 * Runs in a throwaway git repo under /tmp (GitSandboxTrait) — the project's own
 * working tree is never touched.
 *
 * @group release
 * @group git
 */
class RestageScopeReleaseTest extends ReleaseTestCase
{
    use GitSandboxTrait;

    private string $binary;

    protected function setUp(): void
    {
        parent::setUp();

        // Absolute, because the sandbox changes the working directory.
        $this->binary = (string) realpath($this->githooks);

        $this->setUpGitSandbox();
    }

    protected function tearDown(): void
    {
        $this->tearDownGitSandbox();

        parent::tearDown();
    }

    /** @test */
    public function a_restaging_job_leaves_untouched_files_out_of_the_index(): void
    {
        file_put_contents('Fixable.php', "<?php\n// before\n");
        file_put_contents('Bystander.php', "<?php\n// staged version\n");
        shell_exec('git add Fixable.php Bystander.php');

        // The author keeps editing the bystander but does not stage that edit.
        file_put_contents('Bystander.php', "<?php\n// staged version\n// NOT meant for this commit\n");

        $config = <<<'PHP'
<?php
return [
    'flows' => ['fix' => ['jobs' => ['formatter']]],
    'jobs' => [
        'formatter' => [
            'type'     => 'custom',
            'script'   => "printf '<?php\n// after\n' > Fixable.php",
            're-stage' => true,
        ],
    ],
];
PHP;
        file_put_contents('githooks.php', $config);

        exec("$this->binary flow fix --config=githooks.php 2>&1", $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));

        // The fixer's own change reached the index...
        $this->assertStringContainsString('// after', (string) shell_exec('git show :Fixable.php'));

        // ...and the untouched file kept the edit its author never staged.
        $this->assertStringNotContainsString(
            '// NOT meant for this commit',
            (string) shell_exec('git show :Bystander.php'),
            'A file the job never modified must not be re-staged'
        );
        $this->assertStringContainsString(
            'Bystander.php',
            trim((string) shell_exec('git diff --name-only'))
        );
    }
}
