<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Hooks\HookStatusInspector;

/**
 * Test double for HookStatusInspector that returns a configurable
 * hooks-path value instead of shelling out to `git config
 * core.hooksPath` against the enclosing project's repository.
 *
 * The .githooks/ directory is still read from the real filesystem
 * (under $rootPath, defaulting to SystemTestCase::TESTS_PATH), so
 * orphan/synced/missing scenarios can be exercised by placing hook
 * files in testsDir/.githooks/ and calling setHooksPathValue() to
 * simulate each core.hooksPath state.
 */
class HookStatusInspectorFake extends HookStatusInspector
{
    private string $hooksPathValue = '';

    public function __construct(string $rootPath = null)
    {
        parent::__construct($rootPath ?: SystemTestCase::TESTS_PATH);
    }

    public function setHooksPathValue(string $value): void
    {
        $this->hooksPathValue = $value;
    }

    protected function getGitHooksPath(): string
    {
        return $this->hooksPathValue;
    }
}
