<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * 3.7 — `conf:check --format=json` must serialise the FULL execution-option set in
 * its `options` block, with the two budgets shaped symmetrically: both `null` when
 * unset, both objects (`timeBudget{warnAfter,failAfter}` / `memoryBudget{warnAbove,
 * failAbove}`) when declared. The payload the bundled binary emits is what CI and
 * tooling actually parse, so this asserts the complete-and-symmetric schema is
 * embedded in the compiled `.phar`, not merely present in the source.
 *
 * Required as @group release because `timeBudget` was missing from the payload
 * while `memoryBudget` was present; an asserted regression here means the fix is
 * compiled into the distributed binary.
 *
 * @group release
 */
class ConfCheckJsonReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
    }

    /** @test */
    public function options_block_exposes_the_full_set_with_symmetric_budgets(): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions([
                'time-budget'   => ['warn-after' => 10, 'fail-after' => 20],
                'memory-budget' => ['warn-above' => 256, 'fail-above' => 512],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['noop']]])
            ->setV3Jobs(['noop' => ['type' => 'custom', 'script' => 'true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('options', $decoded);

        $expectedKeys = [
            'processes', 'failFast', 'mainBranch', 'fastBranchFallback', 'executablePrefix',
            'reports', 'timeBudget', 'memoryBudget', 'allocator', 'stats', 'historySize',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $decoded['options'], "options must expose '$key' in the .phar");
        }

        // The regression the fix closes: `timeBudget` was absent while `memoryBudget`
        // was serialised. Both budgets must now round-trip symmetrically as objects.
        $this->assertSame(['warnAfter' => 10, 'failAfter' => 20], $decoded['options']['timeBudget']);
        $this->assertSame(256, $decoded['options']['memoryBudget']['warnAbove']);
        $this->assertSame(512, $decoded['options']['memoryBudget']['failAbove']);
    }

    /**
     * `reports` must keep a map shape whatever its content: encoded from an
     * empty PHP array it came out as `[]`, breaking typed consumers on exactly
     * the configs that declare no reports.
     *
     * @test
     */
    public function empty_reports_serialises_as_an_object(): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['noop']]])
            ->setV3Jobs(['noop' => ['type' => 'custom', 'script' => 'true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"reports": {}', $this->getActualOutput());
    }

    /**
     * A job the parser rejects must still be listed, otherwise the documented
     * `jq '.jobs[] | select(.status != "ok")'` recipe reports nothing wrong
     * about the file's worst entry.
     *
     * @test
     */
    public function rejected_jobs_are_listed_with_error_status(): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['noop', 'bad_type']]])
            ->setV3Jobs([
                'noop'     => ['type' => 'custom', 'script' => 'true'],
                'bad_type' => ['type' => 'inventado', 'paths' => ['src']],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(1, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);

        $statuses = [];
        foreach ($decoded['jobs'] as $job) {
            $statuses[$job['name']] = $job['status'];
        }
        $this->assertArrayHasKey('bad_type', $statuses, 'The rejected job must be present in jobs[]');
        $this->assertSame('error', $statuses['bad_type']);
    }

    /**
     * `runner: artisan` builds `php artisan test`. Validating that whole string
     * as a binary name can never resolve, so every Laravel project using the
     * documented runner got a permanent "executable not found" warning.
     *
     * @test
     */
    public function artisan_runner_does_not_report_a_missing_executable(): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['pest_artisan']]])
            ->setV3Jobs(['pest_artisan' => ['type' => 'pest', 'runner' => 'artisan']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $issues = implode(' ', $decoded['jobs'][0]['issues']);

        $this->assertSame('php artisan test', $decoded['jobs'][0]['command']);
        $this->assertStringNotContainsString('not found', $issues);
    }
}
