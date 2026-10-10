<?php

declare(strict_types=1);

namespace Tests\System\Commands;

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
            ->assertExitCode(0)
            ->containsStringInOutput('No flows or jobs configured');
    }

    /** @test */
    public function it_rejects_a_v2_configuration()
    {
        file_put_contents($this->configPath, "<?php return ['Options' => ['execution' => 'full'], 'Tools' => ['phpstan']];");

        $this->artisan("hook:run pre-commit --config=$this->configPath")
            ->assertExitCode(1)
            ->containsStringInOutput('v2 configuration format (Options/Tools) is not supported since 4.0');
    }

    /** @test */
    public function it_shows_message_when_no_hooks_section()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks([])
            ->buildInFileSystem();

        $this->artisan("hook:run pre-commit --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput("No 'hooks' section found");
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

    /** @test */
    public function returns_exit_1_when_config_has_validation_errors()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions([
                'fail-fast' => false,
                'processes' => 1,
                'executable-prefix' => 123,
            ])
            ->buildInFileSystem();

        $this->artisan("hook:run pre-commit --config=$this->configPath")
            ->assertExitCode(1);
    }

    /** @test A .yml is rejected on its extension, before anything is read. */
    public function it_rejects_a_yaml_configuration()
    {
        $yamlPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.yml';
        file_put_contents($yamlPath, "Tools:\n  - phpstan\n  invalid: [not closed\n");

        $this->artisan("hook:run pre-commit --config=$yamlPath")
            ->assertExitCode(1)
            ->containsStringInOutput('YAML configuration files are not supported since 4.0');
    }

    /** @test */
    public function exits_0_when_all_hook_refs_are_skipped_by_execution_conditions()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks([
                'pre-commit' => [
                    ['flow' => 'qa', 'only-on' => ['nonexistent-branch']],
                ],
            ])
            ->buildInFileSystem();

        $this->artisan("hook:run pre-commit --config=$this->configPath")
            ->assertExitCode(0);
    }
}
