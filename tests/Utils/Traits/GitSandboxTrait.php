<?php

declare(strict_types=1);

namespace Tests\Utils\Traits;

/**
 * Sandboxed git working tree for integration tests.
 *
 * Some SUT classes (FileUtils, GitStager) shell out to `git` without a
 * `-C <dir>` flag, so they always operate on the current working
 * directory. To exercise them safely without touching the project's
 * real repo, this trait creates a fresh `/tmp/...` directory, initialises
 * a git repo there, configures a test identity, makes an empty initial
 * commit (so `HEAD` resolves and `git diff --cached` works), and
 * `chdir()`s into it. Tear-down restores the original CWD and recursively
 * removes the sandbox.
 *
 * Without this isolation a `git reset --hard HEAD` in setUp wiped any
 * uncommitted work in the project repo every time `phpunit-git` ran.
 */
trait GitSandboxTrait
{
    /** @var string|null */
    protected $sandboxDir;

    /** @var string|null */
    private $originalCwd;

    protected function setUpGitSandbox(): void
    {
        $this->originalCwd = getcwd() ?: null;

        $this->sandboxDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'githooks-sandbox-'
            . bin2hex(random_bytes(8));

        if (!mkdir($this->sandboxDir, 0755, true) && !is_dir($this->sandboxDir)) {
            throw new \RuntimeException("Could not create git sandbox at {$this->sandboxDir}");
        }

        if (!chdir($this->sandboxDir)) {
            throw new \RuntimeException("Could not chdir into git sandbox at {$this->sandboxDir}");
        }

        // `git init -b <name>` is unsupported before git 2.28; fall back when needed.
        shell_exec('git init --quiet -b master 2>/dev/null || git init --quiet 2>/dev/null');
        shell_exec('git config user.email "test@test.com"');
        shell_exec('git config user.name "Test"');
        // Empty initial commit so HEAD resolves and `git diff --cached` has a baseline.
        shell_exec('git commit --allow-empty --quiet -m "initial" 2>/dev/null');
    }

    protected function tearDownGitSandbox(): void
    {
        if ($this->originalCwd !== null) {
            @chdir($this->originalCwd);
        }
        if ($this->sandboxDir !== null && is_dir($this->sandboxDir)) {
            $this->recursiveDelete($this->sandboxDir);
        }
        $this->sandboxDir = null;
        $this->originalCwd = null;
    }

    private function recursiveDelete(string $dir): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
