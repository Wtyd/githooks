<?php

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 *
 * BUG-1: when a job's tool config strips every input via its internal
 * exclusion list (`excludePaths.analyse` for PHPStan, `--ignore` for PHPCS),
 * the tool exits non-zero with a recognisable marker. The .phar must
 * reinterpret that as `skipped: true` instead of failing the flow.
 *
 * This test ships a `.neon` whose `excludePaths.analyse` covers the very file
 * the job is asked to analyse. Without the fix, PHPStan returns exit 1 +
 * "[ERROR] No files found to analyse." and the job is reported as failed.
 * With the fix, the JSON output reports `skipped: true` and `success: true`.
 */
class EmptyInputToleranceReleaseTest extends ReleaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A valid PHP file in src/. The .neon will exclude src/ entirely, so
        // PHPStan will report "No files found to analyse" with exit 1.
        file_put_contents(
            self::TESTS_PATH . '/src/File.php',
            $this->phpFileBuilder->build()
        );

        $qaDir = self::TESTS_PATH . '/qa';
        if (!is_dir($qaDir)) {
            mkdir($qaDir, 0777, true);
        }

        // .neon paths are relative to the .neon itself.
        // From testsDir/qa/, "../src" points to testsDir/src.
        file_put_contents(
            "$qaDir/phpstan-exclude-all.neon",
            "parameters:\n    level: 0\n    paths:\n        - ../src\n    excludePaths:\n        analyse:\n            - ../src\n"
        );
    }

    /** @test */
    public function phpstan_job_with_all_inputs_excluded_is_reported_as_skipped_in_json()
    {
        // Build a v3-style githooks.php declaring a single phpstan job that
        // analyses testsDir/src — and whose .neon excludes that path entirely.
        // Without the BUG-1 fix this returns failed; with the fix it returns skipped.
        $githooksPhp = $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks([])
            ->setV3Flows([])
            ->setV3Jobs([
                'phpstan_excl' => [
                    'type'             => 'phpstan',
                    'executable-path'  => 'vendor/bin/phpstan',
                    'config'           => self::TESTS_PATH . '/qa/phpstan-exclude-all.neon',
                    'paths'            => [self::TESTS_PATH . '/src'],
                    'level'            => 0,
                ],
            ])
            ->buildV3Php();

        // ReleaseTestCase::tearDown() cleans this up.
        file_put_contents('githooks.php', $githooksPhp);

        $output = [];
        $exitCode = 1;
        exec("$this->githooks job phpstan_excl --format=json 2>/dev/null", $output, $exitCode);
        $json = implode("\n", $output);

        $this->assertSame(0, $exitCode, "githooks job exit code (raw output:\n$json)");

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, "JSON output must parse (raw:\n$json)");
        $this->assertArrayHasKey('jobs', $decoded);
        $this->assertCount(1, $decoded['jobs']);

        $job = $decoded['jobs'][0];
        $this->assertSame('phpstan_excl', $job['name']);
        $this->assertTrue(
            $job['skipped'] ?? false,
            "Job must be reported as skipped — raw job entry:\n" . json_encode($job, JSON_PRETTY_PRINT)
        );
        $this->assertTrue($job['success'] ?? false, 'Skipped jobs do not fail the flow');
        $this->assertNotEmpty($job['skipReason'] ?? '', 'skipReason must be populated');
    }
}
