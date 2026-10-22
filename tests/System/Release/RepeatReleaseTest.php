<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * FEAT-25 — `flow --repeat` driven through the embedded .phar. Fails against a
 * binary without the feature: `--repeat` is an unknown option there (exit 1,
 * no flap report on stdout).
 *
 * @group release
 */
class RepeatReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = self::TESTS_PATH;
        $writer = "$dir/writer.php";
        // Pass k writes the outcome of `T::t` from the script: g passed, n failed, ! nothing.
        file_put_contents($writer, <<<'PHP'
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

        $this->configPath = "$dir/githooks.php";
        $this->writeConfig('g,n,g,n');
    }

    private function writeConfig(string $script): void
    {
        $dir = self::TESTS_PATH;
        $config = [
            'flows' => ['tests' => ['jobs' => ['suite']]],
            'jobs'  => [
                'suite' => [
                    'type'   => 'custom',
                    'script' => PHP_BINARY . " $dir/writer.php $script $dir/counter $dir/junit.xml",
                    'junit'  => "$dir/junit.xml",
                ],
            ],
        ];
        file_put_contents($this->configPath, "<?php\nreturn " . var_export($config, true) . ";\n");
    }

    /** @test */
    public function the_phar_reports_a_test_that_flips_between_passes_as_flaky(): void
    {
        passthru("$this->githooks flow tests --repeat=4 --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded, 'stdout must be the flap report and nothing else');
        $this->assertSame(1, $exitCode);
        $this->assertSame(
            [['job' => 'suite', 'test' => 'T::t', 'class' => 'flaky', 'passed' => 2, 'failed' => 2, 'skipped' => 0, 'absent' => 0]],
            $decoded['tests']
        );
    }

    /** @test */
    public function the_phar_never_reads_a_previous_pass_as_the_result_of_a_crashed_one(): void
    {
        $this->writeConfig('g,!');

        passthru("$this->githooks flow tests --repeat=2 --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(0, $exitCode);
        $this->assertSame([1, [['pass' => 2, 'job' => 'suite', 'reason' => 'missing']]], [
            $decoded['tests'][0]['passed'],
            $decoded['anomalies'],
        ]);
    }
}
