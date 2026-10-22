<?php

declare(strict_types=1);

namespace Tests\System\Commands\FlowCommand;

use Tests\Utils\TestCase\SystemTestCase;

/**
 * `flow --repeat` end to end (FEAT-25): table B of tests/Unit/Flaky/factors.md
 * plus the pathogenic class of the invariant — a pass that writes no junit
 * file while the previous pass's file is still on disk.
 *
 * Each `suite*` job runs a small PHP writer that reads a counter and writes
 * the outcome of the test `T::t` for that pass from a comma-separated script:
 *   g = passed, n = failed, ! = writes nothing (a crashed run).
 */
class RepeatTest extends SystemTestCase
{
    private string $configPath;

    private string $reportPath;

    private string $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
        $this->reportPath = getcwd() . '/' . self::TESTS_PATH . '/flap.json';
        $this->writer = getcwd() . '/' . self::TESTS_PATH . '/writer.php';

        file_put_contents($this->writer, <<<'PHP'
<?php
[, $script, $counter, $out] = $argv;
$pass = is_file($counter) ? (int) file_get_contents($counter) : 0;
file_put_contents($counter, (string) ($pass + 1));
$token = explode(',', $script)[$pass] ?? '!';
if ($token === '!') {
    exit(2);
}
$child = $token === 'n' ? '<failure>boom</failure>' : '';
file_put_contents($out, '<?xml version="1.0"?><testsuites><testsuite name="s">'
    . "<testcase classname=\"T\" name=\"t\">$child</testcase></testsuite></testsuites>");
exit($token === 'n' ? 1 : 0);
PHP);
    }

    /**
     * A custom job that writes `T::t` following $script into its junit file.
     *
     * @return array<string, mixed>
     */
    private function suiteJob(string $script, string $junit = 'junit.xml', string $counter = 'counter'): array
    {
        $dir = self::TESTS_PATH;

        return [
            'type'   => 'custom',
            'script' => PHP_BINARY . " $this->writer $script $dir/$counter $dir/$junit",
            // Relative on purpose: resolved against the working directory.
            'junit'  => "$dir/$junit",
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $jobs
     */
    private function writeConfig(array $jobs): void
    {
        $config = ['flows' => ['tests' => ['jobs' => array_keys($jobs)]], 'jobs' => $jobs];
        file_put_contents($this->configPath, "<?php\nreturn " . var_export($config, true) . ";\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function flapReport(): array
    {
        $decoded = json_decode((string) file_get_contents($this->reportPath), true);
        $this->assertIsArray($decoded, 'the flap report must be one JSON document');

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function testRow(string $test = 'T::t'): array
    {
        foreach ($this->flapReport()['tests'] as $row) {
            if ($row['test'] === $test) {
                return $row;
            }
        }
        $this->fail("No row for $test in the flap report");
    }

    private function passesRun(string $counter = 'counter'): int
    {
        $path = self::TESTS_PATH . "/$counter";

        return is_file($path) ? (int) file_get_contents($path) : 0;
    }

    // ── Table B: when the repeated run happens at all ─────────────────────

    /**
     * @test
     * @dataProvider singleRunInvocations
     */
    public function without_a_repeat_of_two_or_more_the_flow_runs_once(string $flags): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('g,g,g')]);

        $this->artisan("flow tests $flags --config=$this->configPath")
            ->assertExitCode(0)
            ->notContainsStringInOutput('Flaky test report');

        $this->assertSame(1, $this->passesRun());
    }

    /**
     * @return array<string, array{string}>
     */
    public function singleRunInvocations(): array
    {
        return [
            'no --repeat'  => [''],
            '--repeat=1'   => ['--repeat=1'],
        ];
    }

    /** @test */
    public function a_dry_run_ignores_repeat_and_runs_nothing(): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('g,g,g')]);

        $this->artisan("flow tests --repeat=3 --dry-run --config=$this->configPath")
            ->assertExitCode(0)
            ->notContainsStringInOutput('Flaky test report');

        $this->assertSame(0, $this->passesRun());
    }

    /**
     * @test
     * @dataProvider invalidCounts
     */
    public function a_count_that_is_not_a_positive_integer_is_refused_before_running(string $flag, string $value): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('g,g,g')]);

        $this->artisan("flow tests --$flag=$value --config=$this->configPath")
            ->assertExitCode(1)
            ->containsStringInOutput("--$flag expects a positive integer; got '$value'.");

        $this->assertSame(0, $this->passesRun());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function invalidCounts(): array
    {
        return [
            'repeat zero'            => ['repeat', '0'],
            'repeat negative'        => ['repeat', '-2'],
            'repeat not a number'    => ['repeat', 'many'],
            'repeat decimal'         => ['repeat', '1.5'],
            'threshold zero'         => ['flap-threshold', '0'],
            'threshold not a number' => ['flap-threshold', 'x'],
        ];
    }

    /** @test */
    public function a_threshold_without_repeat_warns_and_runs_once(): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('g,g,g')]);

        $this->artisan("flow tests --flap-threshold=2 --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('--flap-threshold has no effect without --repeat=N (N >= 2). Ignoring.');

        $this->assertSame(1, $this->passesRun());
    }

    /**
     * @test
     * @dataProvider singleRunFormats
     */
    public function a_format_that_describes_one_run_is_refused(string $format): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('g,g,g')]);

        $this->artisan("flow tests --repeat=2 --format=$format --config=$this->configPath")
            ->assertExitCode(1)
            ->containsStringInOutput("--format=$format describes a single run and cannot be used with --repeat. Use text or json.");

        $this->assertSame(0, $this->passesRun());
    }

    /**
     * @return array<string, array{string}>
     */
    public function singleRunFormats(): array
    {
        return ['junit' => ['junit'], 'sarif' => ['sarif'], 'codeclimate' => ['codeclimate'], 'claude-code' => ['claude-code']];
    }

    /** @test */
    public function an_unknown_format_falls_back_to_the_text_report(): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('g,g')]);

        $this->artisan("flow tests --repeat=2 --format=csv --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput("Unknown format 'csv'. Using text output.")
            ->containsStringInOutput("Flaky test report: flow 'tests', 2 passes, flap threshold 1");
    }

    /** @test */
    public function a_flow_without_any_junit_file_is_refused(): void
    {
        $this->writeConfig(['lint' => ['type' => 'custom', 'script' => 'true']]);

        $this->artisan("flow tests --repeat=2 --config=$this->configPath")
            ->assertExitCode(1)
            ->containsStringInOutput(
                "--repeat needs at least one job in flow 'tests' that declares 'junit' (or 'log-junit' on phpunit and paratest jobs)."
            );
    }

    /** @test */
    public function two_jobs_writing_the_same_junit_file_are_refused(): void
    {
        $this->writeConfig([
            'suite_a' => $this->suiteJob('g,g', 'shared.xml', 'counter_a'),
            'suite_b' => $this->suiteJob('g,g', 'shared.xml', 'counter_b'),
        ]);

        $shared = self::TESTS_PATH . '/shared.xml';
        $this->artisan("flow tests --repeat=2 --config=$this->configPath")
            ->assertExitCode(1)
            ->containsStringInOutput(
                "Jobs 'suite_a' and 'suite_b' both write their junit file to '$shared'; "
                . "--repeat cannot tell their tests apart. Give each job its own 'junit' path."
            );

        $this->assertSame([0, 0], [$this->passesRun('counter_a'), $this->passesRun('counter_b')]);
    }

    // ── What a repeated run reports ──────────────────────────────────────

    /** @test */
    public function it_runs_every_pass_even_when_they_fail(): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('n,n,n,n')]);

        $this->artisan("flow tests --repeat=4 --format=json --output=$this->reportPath --config=$this->configPath")
            ->assertExitCode(1);

        $this->assertSame(4, $this->passesRun());
    }

    /** @test */
    public function a_test_that_both_passes_and_fails_is_flaky_and_fails_the_command(): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('g,n,g,n')]);

        $this->artisan("flow tests --repeat=4 --format=json --output=$this->reportPath --config=$this->configPath")
            ->assertExitCode(1);

        $this->assertSame(
            ['job' => 'suite', 'test' => 'T::t', 'class' => 'flaky', 'passed' => 2, 'failed' => 2, 'skipped' => 0, 'absent' => 0],
            $this->testRow()
        );
    }

    /** @test */
    public function below_the_threshold_a_flaky_test_is_suspect_and_the_command_passes(): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('g,g,g,n')]);

        $this->artisan("flow tests --repeat=4 --flap-threshold=2 --format=json --output=$this->reportPath --config=$this->configPath")
            ->assertExitCode(0);

        $this->assertSame(['suspect', 2], [$this->testRow()['class'], $this->flapReport()['threshold']]);
    }

    /**
     * The pathogenic class: the pass that crashes finds the previous pass's
     * junit file on disk. Without clearing it first, `T::t` would read as
     * passed twice.
     *
     * @test
     */
    public function a_pass_that_writes_no_junit_never_reads_the_previous_one(): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('g,!')]);

        $this->artisan("flow tests --repeat=2 --format=json --output=$this->reportPath --config=$this->configPath")
            ->assertExitCode(0);

        $this->assertSame([1, 0], [$this->testRow()['passed'], $this->testRow()['failed']]);
        $this->assertSame([['pass' => 2, 'job' => 'suite', 'reason' => 'missing']], $this->flapReport()['anomalies']);
    }

    /** @test */
    public function a_junit_file_left_by_an_earlier_run_is_not_read_as_a_result(): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('!,!')]);
        file_put_contents(
            self::TESTS_PATH . '/junit.xml',
            '<?xml version="1.0"?><testsuites><testsuite name="s"><testcase classname="T" name="t"/></testsuite></testsuites>'
        );

        $this->artisan("flow tests --repeat=2 --format=json --output=$this->reportPath --config=$this->configPath")
            ->assertExitCode(1);

        $this->assertSame([[], ['suite']], [$this->flapReport()['tests'], $this->flapReport()['inconclusiveJobs']]);
    }

    /** @test */
    public function a_job_without_junit_is_counted_per_pass(): void
    {
        $this->writeConfig([
            'suite' => $this->suiteJob('g,g,g'),
            'lint'  => ['type' => 'custom', 'script' => 'false'],
        ]);

        $this->artisan("flow tests --repeat=3 --format=json --output=$this->reportPath --config=$this->configPath")
            ->assertExitCode(0);

        $this->assertSame(
            ['name' => 'lint', 'junit' => null, 'passed' => 0, 'failed' => 3, 'skipped' => 0, 'readablePasses' => 0],
            $this->flapReport()['jobs'][1]
        );
    }

    /** @test */
    public function the_text_report_logs_each_pass_and_lists_the_flaky_test(): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('g,n')]);

        $this->artisan("flow tests --repeat=2 --config=$this->configPath")
            ->assertExitCode(1)
            ->containsStringInOutput('Pass 1/2: jobs 1 passed, 0 failed, 0 skipped')
            ->containsStringInOutput('Pass 2/2: jobs 0 passed, 1 failed, 0 skipped')
            ->containsStringInOutput('  FLAKY     1/2  passed  T::t  (suite)');
    }

    /** @test */
    public function options_that_describe_a_single_run_are_reported_as_ignored(): void
    {
        $this->writeConfig(['suite' => $this->suiteJob('g,g')]);

        $this->artisan("flow tests --repeat=2 --save-history --monitor --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('Warning: --save-history, --monitor ignored with --repeat.');
    }
}
