<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;

class SystemInfoCommandTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode()->buildInFileSystem();
    }

    /** @test */
    public function it_shows_cpu_and_processes_info()
    {
        $this->artisan("system:info --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['Available CPUs', 'Configured processes'];
    }

    /** @test */
    public function it_shows_ok_when_processes_within_budget()
    {
        $this->artisan("system:info --config=$this->configPath")
            ->assertExitCode(0);

        // Default is processes=1, which is always within any CPU budget
        $this->containsStringInOutput = ['OK'];
    }

    /** @test */
    public function it_warns_when_processes_exceeds_cpus()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions(['fail-fast' => false, 'processes' => 9999])
            ->buildInFileSystem();

        $this->artisan("system:info --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['exceeds'];
    }

    /** @test */
    public function handles_parser_exception_and_still_exits_with_success()
    {
        $yamlPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.yml';
        file_put_contents($yamlPath, "Tools:\n  - phpstan\n  invalid: [not closed\n");

        $this->artisan("system:info --config=$yamlPath")
            ->assertExitCode(0);
    }
}
