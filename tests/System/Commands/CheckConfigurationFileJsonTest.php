<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;

/**
 * FEAT-20 — `conf:check --format=json`. Asserts the structured payload and that
 * the text path is unaffected (AC-006 lives in CheckConfigurationFileCommandTest).
 */
class CheckConfigurationFileJsonTest extends SystemTestCase
{
    private string $configPath;

    private int $lastExit = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
    }

    /**
     * Run the command capturing stdout and decoding it as JSON. PendingCommand
     * re-echoes its captured output, so an outer buffer collects the payload.
     *
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

    /** @test AC-001 */
    public function it_emits_parseable_json_for_a_valid_v3_config()
    {
        $this->configurationFileBuilder->enableV3Mode()->buildInFileSystem();

        $payload = $this->runJsonCommand("conf:check --format=json --config=$this->configPath");

        $this->assertSame(0, $this->lastExit);
        $this->assertSame(1, $payload['version']);
        $this->assertTrue($payload['valid']);
        $this->assertFalse($payload['legacy']);
        $this->assertSame([], $payload['errors']);
        $this->assertNotEmpty($payload['jobs']);
        $this->assertSame('ok', $payload['jobs'][0]['status']);
        // No ANSI escapes nor table glyphs leaked into the payload.
        $this->assertArrayHasKey('options', $payload);
        $this->assertArrayHasKey('hooks', $payload);
        $this->assertArrayHasKey('flows', $payload);
    }

    /** @test AC-002 — errors are structured and the exit code matches text mode (1) */
    public function it_reports_errors_structured_and_exits_nonzero()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions(['reports' => ['xml' => 'reports/qa.xml']])
            ->buildInFileSystem();

        $payload = $this->runJsonCommand("conf:check --format=json --config=$this->configPath");

        $this->assertSame(1, $this->lastExit);
        $this->assertFalse($payload['valid']);
        $this->assertNotEmpty($payload['errors']);
        $this->assertStringContainsString("invalid format 'xml'", implode(' ', $payload['errors']));
    }

    /** @test A job with validation issues is reported as a warning without flipping the exit code */
    public function it_reports_job_warnings_without_failing()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions(['reports' => ['sarif' => getcwd() . '/' . self::TESTS_PATH . '/missing-dir/qa.sarif']])
            ->buildInFileSystem();
        @rmdir(getcwd() . '/' . self::TESTS_PATH . '/missing-dir');

        $payload = $this->runJsonCommand("conf:check --format=json --config=$this->configPath");

        $this->assertSame(0, $this->lastExit);
        $this->assertTrue($payload['valid']);
        $this->assertNotEmpty($payload['warnings']);
    }

    /** @test Legacy config emits a JSON marker and the migrate hint (decision 3) */
    public function it_emits_legacy_marker_and_migrate_hint_for_legacy_config()
    {
        $legacyBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);
        $legacyBuilder->buildInFileSystem();

        $payload = $this->runJsonCommand("conf:check --format=json --config=$this->configPath");

        $this->assertTrue($payload['legacy']);
        $this->assertArrayNotHasKey('jobs', $payload);
        $this->assertSame("Run 'githooks conf:migrate' to upgrade to v3.", $payload['hint']);
    }

    /** @test AC-005 — invalid format warns and falls back to text (tables, not JSON) */
    public function invalid_format_falls_back_to_text_output()
    {
        $this->configurationFileBuilder->enableV3Mode()->buildInFileSystem();

        $this->artisan("conf:check --format=csv --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('Configuration file:');
    }
}
