<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;

/**
 * FEAT-20 — `system:info --format=json`.
 */
class SystemInfoCommandJsonTest extends SystemTestCase
{
    private string $configPath;

    private int $lastExit = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function runJsonCommand(string $command): array
    {
        ob_start();
        $this->lastExit = $this->artisan($command)->run();
        $output = trim((string) ob_get_clean());

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "Expected a JSON object on stdout, got:\n$output");

        return $decoded;
    }

    /** @test AC-004 — payload is {version, cpus, processes, warning} */
    public function it_emits_cpus_processes_and_null_warning_within_budget()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions(['fail-fast' => false, 'processes' => 2])
            ->buildInFileSystem();

        $payload = $this->runJsonCommand("system:info --format=json --config=$this->configPath");

        $this->assertSame(0, $this->lastExit);
        $this->assertSame(['version', 'cpus', 'processes', 'warning'], array_keys($payload));
        $this->assertSame(1, $payload['version']);
        $this->assertIsInt($payload['cpus']);
        $this->assertSame(2, $payload['processes']);
        $this->assertNull($payload['warning']);
    }

    /** @test Over-subscription produces a non-null warning */
    public function it_emits_warning_when_processes_exceeds_cpus()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions(['fail-fast' => false, 'processes' => 9999])
            ->buildInFileSystem();

        $payload = $this->runJsonCommand("system:info --format=json --config=$this->configPath");

        $this->assertSame(9999, $payload['processes']);
        $this->assertNotNull($payload['warning']);
        $this->assertStringContainsString('exceeds available CPUs', $payload['warning']);
    }

    /** @test No usable config → processes null, warning null (no crash) */
    public function it_emits_null_processes_when_no_config()
    {
        $missing = getcwd() . '/' . self::TESTS_PATH . '/does-not-exist.php';

        $payload = $this->runJsonCommand("system:info --format=json --config=$missing");

        $this->assertSame(0, $this->lastExit);
        $this->assertNull($payload['processes']);
        $this->assertNull($payload['warning']);
    }

    /** @test AC-005 — invalid format warns and falls back to text */
    public function invalid_format_falls_back_to_text_output()
    {
        $this->configurationFileBuilder->enableV3Mode()->buildInFileSystem();

        $this->artisan("system:info --format=csv --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('Available CPUs');
    }
}
