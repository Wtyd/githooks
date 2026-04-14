<?php

declare(strict_types=1);

namespace Tests\Unit\Output\CI;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\CI\CIEnvironment;

class CIEnvironmentTest extends TestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->originalEnv = [
            'GITHUB_ACTIONS' => getenv('GITHUB_ACTIONS'),
            'GITLAB_CI' => getenv('GITLAB_CI'),
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
    }

    /** @test */
    public function it_detects_github_actions()
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('GITLAB_CI');

        $this->assertSame(CIEnvironment::GITHUB_ACTIONS, CIEnvironment::detect());
    }

    /** @test */
    public function it_detects_gitlab_ci()
    {
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI=true');

        $this->assertSame(CIEnvironment::GITLAB_CI, CIEnvironment::detect());
    }

    /** @test */
    public function it_returns_none_when_no_ci_detected()
    {
        putenv('GITHUB_ACTIONS');
        putenv('GITLAB_CI');

        $this->assertSame(CIEnvironment::NONE, CIEnvironment::detect());
    }

    /** @test */
    public function github_actions_takes_priority_over_gitlab()
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('GITLAB_CI=true');

        $this->assertSame(CIEnvironment::GITHUB_ACTIONS, CIEnvironment::detect());
    }
}
