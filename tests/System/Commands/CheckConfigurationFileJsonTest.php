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
        // The `options` object exposes the full execution-option set with a stable
        // shape — the two budgets are serialised symmetrically (null when unset),
        // so a consumer never has to guess whether a key is simply absent.
        foreach (
            ['processes', 'failFast', 'mainBranch', 'fastBranchFallback', 'executablePrefix',
                'reports', 'timeBudget', 'memoryBudget', 'allocator', 'stats', 'historySize'
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $payload['options'], "options must expose '$key'");
        }
        $this->assertNull($payload['options']['timeBudget']);
        $this->assertNull($payload['options']['memoryBudget']);
    }

    /** @test The budgets serialise symmetrically as objects when both are configured */
    public function it_serialises_both_budgets_as_objects_when_set()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions([
                'time-budget' => ['warn-after' => 10, 'fail-after' => 20],
                'memory-budget' => ['warn-above' => 256, 'fail-above' => 512],
            ])
            ->buildInFileSystem();

        $payload = $this->runJsonCommand("conf:check --format=json --config=$this->configPath");

        $this->assertSame(['warnAfter' => 10, 'failAfter' => 20], $payload['options']['timeBudget']);
        $this->assertArrayHasKey('warnAbove', $payload['options']['memoryBudget']);
        $this->assertArrayHasKey('failAbove', $payload['options']['memoryBudget']);
    }

    /**
     * `reports` is a map of format => path. An empty PHP array encodes as `[]`,
     * so a consumer that unmarshals the field into a map breaks on exactly the
     * configs that declare no reports. The shape must not depend on the content.
     *
     * @test
     */
    public function it_serialises_an_empty_reports_map_as_an_object()
    {
        $this->configurationFileBuilder->enableV3Mode()->buildInFileSystem();

        ob_start();
        $this->artisan("conf:check --format=json --config=$this->configPath")->run();
        $raw = trim((string) ob_get_clean());

        $this->assertStringContainsString('"reports": {}', $raw);
        $this->assertStringNotContainsString('"reports": []', $raw);
    }

    /**
     * A job the parser rejects is the most broken entry in the file, yet it was
     * the only one missing from `jobs[]` — so the documented recipe
     * `jq '.jobs[] | select(.status != "ok")'` reported nothing wrong with it.
     *
     * @test
     */
    public function it_lists_rejected_jobs_with_error_status()
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Jobs([
                'good'     => ['type' => 'phpcs', 'paths' => ['src'], 'standard' => 'PSR12'],
                'bad_type' => ['type' => 'inventado', 'paths' => ['src']],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['good', 'bad_type']]])
            ->buildInFileSystem();

        $payload = $this->runJsonCommand("conf:check --format=json --config=$this->configPath");

        $this->assertSame(1, $this->lastExit);
        $this->assertFalse($payload['valid']);

        $byName = [];
        foreach ($payload['jobs'] as $job) {
            $byName[$job['name']] = $job;
        }

        $this->assertArrayHasKey('bad_type', $byName, 'A rejected job must still be listed in jobs[]');
        $this->assertSame('error', $byName['bad_type']['status']);
        $this->assertStringContainsString('is not a supported tool', implode(' ', $byName['bad_type']['issues']));
        $this->assertSame('ok', $byName['good']['status']);
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
