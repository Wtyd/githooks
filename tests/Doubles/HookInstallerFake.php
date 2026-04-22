<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Hooks\HookInstaller;

/**
 * Test double for HookInstaller that keeps the real install/clean
 * filesystem logic (writes under $rootPath/.githooks) but no-ops the
 * git config shell_exec calls that would otherwise mutate the
 * enclosing project's git configuration.
 *
 * The captured flags (configureHooksPathCalls, unsetHooksPathCalls)
 * let tests assert that the command triggered those side-effects
 * without actually invoking git.
 */
class HookInstallerFake extends HookInstaller
{
    public int $configureHooksPathCalls = 0;

    public int $unsetHooksPathCalls = 0;

    public function __construct(string $rootPath = null)
    {
        parent::__construct($rootPath ?: SystemTestCase::TESTS_PATH);
    }

    protected function configureHooksPath(): void
    {
        $this->configureHooksPathCalls++;
    }

    protected function unsetHooksPath(): void
    {
        $this->unsetHooksPathCalls++;
    }
}
