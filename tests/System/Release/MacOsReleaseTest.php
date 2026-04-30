<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * macOS-only release tests for the compiled .phar.
 *
 * The general @group release suite runs cross-OS via the matrix in
 * release.yml; this trimmed group is a regression net specifically for
 * Darwin: it boots the .phar, runs the smallest possible end-to-end
 * sequential and parallel flows, and dumps stdout/stderr/parsed JSON
 * onto stderr if any of them fail so the next CI run is self-diagnosing
 * without a second pass.
 *
 * History: this group was introduced when macos-latest started reporting
 * exit 127 ("sh: /bin/true: No such file or directory") for custom jobs
 * that invoked `/bin/true` by absolute path. The fixture scripts were
 * migrated to bare `true` (shell built-in / PATH lookup) which works on
 * every supported runner; these tests stay as a permanent guard against
 * future Darwin-only regressions in the same code paths.
 *
 * @group release-macos
 */
class MacOsReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('release-macos tests only run on macOS runners');
        }

        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    /**
     * @test
     * Smoke: the compiled .phar boots on macOS and answers the three
     * stateless introspection commands. If this fails, the build is
     * broken at the binary level and any other diagnostic is moot.
     */
    public function phar_boots_and_responds_to_introspection_commands_on_macos(): void
    {
        $versionStr = (string) shell_exec("$this->githooks --version 2>&1");
        $this->assertNotSame('', trim($versionStr), '--version produced empty output');
        $this->assertStringContainsString('GitHooks', $versionStr);

        passthru("$this->githooks --help > /dev/null 2>&1", $helpExit);
        $this->assertSame(0, $helpExit, '--help should exit 0');

        passthru("$this->githooks system:info > /dev/null 2>&1", $sysExit);
        $this->assertSame(0, $sysExit, 'system:info should exit 0');
    }

    /**
     * @test
     * Sequential flow with a single custom job that runs `true`. The
     * expected exit code is 0; any deviation prints the full diagnostic
     * payload (stdout, stderr, parsed JSON, the per-job exitCode) into
     * stderr so the CI run is self-explanatory.
     */
    public function sequential_flow_with_true_script_exits_zero_on_macos(): void
    {
        file_put_contents(
            $this->configPath,
            $this->configurationFileBuilder->buildV3Php()
        );

        $stdoutPath = self::TESTS_PATH . '/diag-seq-stdout.log';
        $stderrPath = self::TESTS_PATH . '/diag-seq-stderr.log';

        // Bypass passthru's output capture so we can read both streams
        // verbatim and dump them on failure.
        $cmd = "$this->githooks flow qa --format=json --config=$this->configPath > $stdoutPath 2> $stderrPath";
        exec($cmd, $unused, $exitCode);

        $stdout = (string) file_get_contents($stdoutPath);
        $stderr = (string) file_get_contents($stderrPath);
        @unlink($stdoutPath);
        @unlink($stderrPath);

        if ($exitCode !== 0) {
            $this->dumpDiagnostic('sequential', $exitCode, $stdout, $stderr);
        }

        $this->assertSame(0, $exitCode, "sequential 'true' script must exit 0 on macOS");

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, 'stdout was not parseable JSON: ' . $stdout);
        $this->assertSame(2, $decoded['version'] ?? null);
        $this->assertNotEmpty($decoded['jobs'] ?? [], 'jobs[] must not be empty');
        foreach ($decoded['jobs'] as $job) {
            $this->assertSame(
                0,
                $job['exitCode'] ?? null,
                sprintf("job '%s' reported exitCode %s on macOS", $job['name'] ?? '?', var_export($job['exitCode'] ?? null, true))
            );
        }
    }

    /**
     * @test
     * Parallel flow with three custom jobs running `true` under
     * --processes=2. Same diagnostic strategy as the sequential test;
     * targets the executeParallel() path that drives the dashboard, the
     * memory handler instantiation and the pool admission cycle.
     */
    public function parallel_flow_with_true_script_exits_zero_on_macos(): void
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['fail-fast' => false, 'processes' => 2])
            ->setV3Flows(['qa' => ['jobs' => ['a', 'b', 'c']]])
            ->setV3Jobs([
                'a' => ['type' => 'custom', 'script' => 'true'],
                'b' => ['type' => 'custom', 'script' => 'true'],
                'c' => ['type' => 'custom', 'script' => 'true'],
            ]);
        file_put_contents(
            $this->configPath,
            $this->configurationFileBuilder->buildV3Php()
        );

        $stdoutPath = self::TESTS_PATH . '/diag-par-stdout.log';
        $stderrPath = self::TESTS_PATH . '/diag-par-stderr.log';

        $cmd = "$this->githooks flow qa --format=json --config=$this->configPath > $stdoutPath 2> $stderrPath";
        exec($cmd, $unused, $exitCode);

        $stdout = (string) file_get_contents($stdoutPath);
        $stderr = (string) file_get_contents($stderrPath);
        @unlink($stdoutPath);
        @unlink($stderrPath);

        if ($exitCode !== 0) {
            $this->dumpDiagnostic('parallel', $exitCode, $stdout, $stderr);
        }

        $this->assertSame(0, $exitCode, "parallel 'true' script must exit 0 on macOS");

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded);
        $this->assertSame(3, count($decoded['jobs'] ?? []));
        foreach ($decoded['jobs'] as $job) {
            $this->assertSame(
                0,
                $job['exitCode'] ?? null,
                sprintf("job '%s' reported exitCode %s on macOS", $job['name'] ?? '?', var_export($job['exitCode'] ?? null, true))
            );
        }
    }

    /**
     * Print every piece of state the runtime exposes about a failing flow
     * so the CI log has all the information needed to triage the next
     * regression without re-running the job. Goes to STDERR so it does
     * not interleave with phpunit's structured output.
     */
    private function dumpDiagnostic(string $label, int $exitCode, string $stdout, string $stderr): void
    {
        $decoded = json_decode($stdout, true);
        $jobsBlock = is_array($decoded) && isset($decoded['jobs'])
            ? json_encode($decoded['jobs'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '(stdout did not parse as JSON)';

        $message = "\n=== macOS RELEASE DIAGNOSTIC [$label] ===\n"
            . "exit code: $exitCode\n"
            . "--- stdout (length=" . strlen($stdout) . ") ---\n"
            . $stdout
            . "\n--- stderr (length=" . strlen($stderr) . ") ---\n"
            . $stderr
            . "\n--- jobs[] block ---\n"
            . $jobsBlock
            . "\n=== end macOS RELEASE DIAGNOSTIC ===\n";

        fwrite(STDERR, $message);
    }
}
