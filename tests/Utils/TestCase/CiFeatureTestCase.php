<?php

declare(strict_types=1);

namespace Tests\Utils\TestCase;

use PHPUnit\Framework\TestCase;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\Traits\FileSystemTrait;

/**
 * Base for `@group ci-features` tests. These tests verify that fast-branch,
 * time-budget and memory-budget work end-to-end on real GitHub Actions
 * runners across Linux/Windows/macOS.
 *
 * Unlike SystemTestCase, this base does NOT bind any fakes — the real
 * binary is invoked as a subprocess so child processes, the RSS sampler
 * and `git` actually run. Unlike ReleaseTestCase, this base does NOT
 * require the compiled .phar — it invokes `php githooks` directly from
 * the source checkout, which is what the matrix CI cell uses too.
 *
 * The verification objective: prove that the cross-OS behaviour of
 * these three features matches the contract documented in their specs
 * (executionMode, threshold.warned/failed, timeBudget, memoryBudget,
 * stats.memory.flowPeak).
 */
abstract class CiFeatureTestCase extends TestCase
{
    use FileSystemTrait;

    public const TESTS_PATH = SystemTestCase::TESTS_PATH;

    protected string $configPath;

    protected ConfigurationFileBuilder $configurationFileBuilder;

    /** Project root (where `githooks` entrypoint lives). */
    protected string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = dirname(__DIR__, 3);

        $this->deleteDirStructure();
        $this->createDirStructure();

        $this->configPath = $this->projectRoot
            . DIRECTORY_SEPARATOR
            . self::TESTS_PATH
            . DIRECTORY_SEPARATOR
            . 'githooks.php';

        $this->configurationFileBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);
        $this->configurationFileBuilder->enableV3Mode();
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
        parent::tearDown();
    }

    /**
     * Persist the current ConfigurationFileBuilder state to $this->configPath.
     */
    protected function writeConfig(): void
    {
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());
    }

    /**
     * Invoke the real `php githooks` binary from the source checkout.
     *
     * @param string      $args Command + flags, e.g. "flow qa --format=json --config=/abs/path".
     * @param string|null $cwd  Working directory to run from. Defaults to the project root.
     *                          Pass a temp git repository path for fast-branch tests.
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    protected function runGithooks(string $args, ?string $cwd = null): array
    {
        $php = PHP_BINARY;
        $entrypoint = $this->projectRoot . DIRECTORY_SEPARATOR . 'githooks';

        $stdoutFile = tempnam(sys_get_temp_dir(), 'gh-stdout-');
        $stderrFile = tempnam(sys_get_temp_dir(), 'gh-stderr-');

        $cmd = sprintf(
            '%s %s %s > %s 2> %s',
            escapeshellarg($php),
            escapeshellarg($entrypoint),
            $args,
            escapeshellarg($stdoutFile),
            escapeshellarg($stderrFile)
        );

        $exitCode = 0;
        $previousCwd = getcwd();
        chdir($cwd ?? $this->projectRoot);
        try {
            // Using shell to support `2>` redirection portably across
            // platforms; PHP `proc_open` would also work but adds noise.
            exec($cmd, $_, $exitCode);
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }

        $stdout = (string) @file_get_contents($stdoutFile);
        $stderr = (string) @file_get_contents($stderrFile);
        @unlink($stdoutFile);
        @unlink($stderrFile);

        return [
            'exitCode' => $exitCode,
            'stdout'   => $stdout,
            'stderr'   => $stderr,
        ];
    }

    /**
     * Decode a JSON-v2 stdout produced by `flow|flows|job ... --format=json`.
     *
     * @return array<string, mixed>
     */
    protected function decodeJsonOutput(string $stdout): array
    {
        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, "stdout is not valid JSON: " . substr($stdout, 0, 500));
        $this->assertSame(2, $decoded['version'] ?? null, 'JSON output is not v2');
        return $decoded;
    }

    /**
     * Helper to find a job block in JSON v2 output by name.
     *
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    protected function findJob(array $decoded, string $jobName): array
    {
        foreach (($decoded['jobs'] ?? []) as $job) {
            if (($job['name'] ?? null) === $jobName) {
                return $job;
            }
        }
        $this->fail("Job '$jobName' not found in JSON output. Available: "
            . implode(', ', array_column($decoded['jobs'] ?? [], 'name')));
    }
}
