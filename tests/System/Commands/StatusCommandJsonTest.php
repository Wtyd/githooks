<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;

/**
 * FEAT-20 — `status --format=json`.
 */
class StatusCommandJsonTest extends SystemTestCase
{
    private string $configPath;

    private int $lastExit = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode()->buildInFileSystem();
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

    /** @test AC-003 — mixed event states surface with their status and targets */
    public function it_emits_parseable_json_with_events_and_targets()
    {
        $payload = $this->runJsonCommand("status --format=json --config=$this->configPath");

        $this->assertSame(0, $this->lastExit);
        $this->assertSame(1, $payload['version']);
        $this->assertArrayHasKey('configured', $payload['hooksPath']);
        $this->assertNotEmpty($payload['events']);

        $event = $payload['events'][0];
        $this->assertArrayHasKey('event', $event);
        $this->assertContains($event['status'], ['synced', 'missing', 'orphan']);
        $this->assertIsArray($event['targets']);
    }

    /** @test Legacy config yields a structured error and exit 1 (parseable stdout) */
    public function it_emits_structured_error_for_legacy_config()
    {
        $legacyBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);
        $legacyBuilder->buildInFileSystem();

        $payload = $this->runJsonCommand("status --format=json --config=$this->configPath");

        $this->assertSame(1, $this->lastExit);
        $this->assertSame(1, $payload['version']);
        $this->assertStringContainsString('requires v3', $payload['error']);
    }

    /** @test AC-005 — invalid format warns and falls back to text */
    public function invalid_format_falls_back_to_text_output()
    {
        $this->artisan("status --format=csv --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('GitHooks Status');
    }
}
