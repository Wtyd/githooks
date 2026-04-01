<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;

class HookRunCommandTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode()->buildInFileSystem();
    }

    /** @test */
    public function it_runs_flows_for_configured_event()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks(['pre-push' => ['qa']])
            ->setV3Jobs([
                'phpcs_src' => ['type' => 'custom', 'script' => 'echo ok1'],
                'phpstan_src' => ['type' => 'custom', 'script' => 'echo ok2'],
            ])
            ->buildInFileSystem();

        $this->artisan("hook:run pre-push --config=$this->configPath")
            ->assertExitCode(0);
    }

    /** @test */
    public function it_shows_message_when_event_not_configured()
    {
        $this->artisan("hook:run post-merge --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['No flows or jobs configured'];
    }

    /** @test */
    public function it_shows_error_for_legacy_config()
    {
        $legacyBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);
        $legacyBuilder->buildInFileSystem();

        $this->artisan("hook:run pre-commit --config=$this->configPath")
            ->assertExitCode(1);

        $this->containsStringInOutput = ['requires v3'];
    }

    /** @test */
    public function it_shows_message_when_no_hooks_section()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks([])
            ->buildInFileSystem();

        $this->artisan("hook:run pre-commit --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['No flows or jobs configured'];
    }

    /** @test */
    public function it_returns_exit_1_when_a_job_fails()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks(['pre-push' => ['qa']])
            ->setV3Flows(['qa' => ['jobs' => ['fail_job']]])
            ->setV3Jobs(['fail_job' => ['type' => 'custom', 'script' => 'echo fail && exit 1']])
            ->buildInFileSystem();

        $this->artisan("hook:run pre-push --config=$this->configPath")
            ->assertExitCode(1);
    }
}
