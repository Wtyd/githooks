<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;

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
}
