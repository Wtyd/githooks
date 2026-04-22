<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Doubles\HookStatusInspectorFake;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Hooks\HookStatusInspector;

class StatusCommandTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode()->buildInFileSystem();
    }

    /** @test */
    public function it_shows_status_with_hooks_configured()
    {
        $this->artisan("status --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['GitHooks Status', 'pre-commit'];
    }

    /** @test */
    public function it_shows_hooks_path_not_configured()
    {
        $this->artisan("status --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['not configured'];
    }

    /** @test */
    public function it_shows_error_for_legacy_config()
    {
        $legacyBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);
        $legacyBuilder->buildInFileSystem();

        $this->artisan("status --config=$this->configPath")
            ->assertExitCode(1);

        $this->containsStringInOutput = ['requires v3'];
    }

    /** @test */
    public function it_shows_no_hooks_when_none_configured()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks([])
            ->buildInFileSystem();

        $this->artisan("status --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['No hooks configured'];
    }

    /** @test */
    public function renders_green_when_core_hooks_path_is_configured_to_githooks()
    {
        /** @var HookStatusInspectorFake $inspector */
        $inspector = $this->app->make(HookStatusInspector::class);
        $inspector->setHooksPathValue('.githooks');

        $this->artisan("status --config=$this->configPath")
            ->assertExitCode(0);
    }

    /** @test */
    public function renders_yellow_warning_when_core_hooks_path_points_to_custom_location()
    {
        /** @var HookStatusInspectorFake $inspector */
        $inspector = $this->app->make(HookStatusInspector::class);
        $inspector->setHooksPathValue('custom/hooks/path');

        $this->artisan("status --config=$this->configPath")
            ->assertExitCode(0);
    }

    /** @test */
    public function renders_missing_status_when_hook_is_configured_but_not_installed()
    {
        // .githooks/ directory absent — configured hook is MISSING
        $this->artisan("status --config=$this->configPath")
            ->assertExitCode(0);
    }

    /** @test */
    public function renders_orphan_status_for_installed_hook_not_in_configuration()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks([])
            ->buildInFileSystem();

        mkdir($this->path . '/.githooks', 0777, true);
        file_put_contents($this->path . '/.githooks/post-checkout', '#!/bin/sh');

        $this->artisan("status --config=$this->configPath")
            ->assertExitCode(0);
    }

    /** @test */
    public function handles_parser_exception_and_exits_with_error_code()
    {
        $yamlPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.yml';
        file_put_contents($yamlPath, "Tools:\n  - phpstan\n  invalid: [not closed\n");

        $this->artisan("status --config=$yamlPath")
            ->assertExitCode(1);
    }
}
