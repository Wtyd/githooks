<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;

class FlowsCommandTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
    }

    /**
     * Build a fixture with three normal flows and a meta-flow ci-pack.
     */
    private function buildMultiFlowFixture(): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks(['pre-commit' => ['qa']])
            ->setV3Flows([
                'qa'      => ['jobs' => ['job_a', 'job_b']],
                'lint'    => ['jobs' => ['job_b', 'job_c']],
                'tests'   => ['jobs' => ['job_d']],
                'ci-pack' => [
                    'flows'   => ['qa', 'lint'],
                    'options' => ['processes' => 4, 'fail-fast' => true],
                ],
            ])
            ->setV3Jobs([
                'job_a' => ['type' => 'custom', 'script' => 'true'],
                'job_b' => ['type' => 'custom', 'script' => 'true'],
                'job_c' => ['type' => 'custom', 'script' => 'true'],
                'job_d' => ['type' => 'custom', 'script' => 'true'],
            ])
            ->buildInFileSystem();
    }

    /** @test */
    public function single_flow_degenerate_runs_as_a_normal_flow()
    {
        $this->buildMultiFlowFixture();

        $this->artisan("flows qa --config=$this->configPath")
            ->assertExitCode(0);

        $this->containsStringInOutput = ['passed'];
    }

    /** @test */
    public function single_flow_degenerate_omits_flows_field_in_json()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows qa --format=json --config=$this->configPath");
        $this->assertSame('qa', $output['flow']);
        $this->assertArrayNotHasKey('flows', $output);
    }

    /** @test */
    public function single_flow_degenerate_matches_flow_command_output()
    {
        $this->buildMultiFlowFixture();

        $flowOut  = $this->runFlowJson("flow qa --format=json --config=$this->configPath");
        $flowsOut = $this->runJson("flows qa --format=json --config=$this->configPath");

        // Identifiers, executed jobs, and effectiveOptions must match.
        $this->assertSame($flowOut['flow'], $flowsOut['flow']);
        $this->assertSame(
            array_column($flowOut['jobs'], 'name'),
            array_column($flowsOut['jobs'], 'name')
        );
        $this->assertSame(
            $flowOut['effectiveOptions'],
            $flowsOut['effectiveOptions'],
            'effectiveOptions must be identical across `flow X` and `flows X`'
        );
        $this->assertArrayNotHasKey('flows', $flowOut);
        $this->assertArrayNotHasKey('flows', $flowsOut);
    }

    /**
     * @return array<string, mixed>
     */
    private function runFlowJson(string $command): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'flowjson_');
        try {
            $this->artisan(trim("$command --output=$tmp"));
            $payload = (string) file_get_contents($tmp);
            $decoded = json_decode($payload, true);
            $this->assertIsArray($decoded, "Expected JSON for flow at $tmp, got:\n$payload");
            return $decoded;
        } finally {
            @unlink($tmp);
        }
    }

    /** @test */
    public function ad_hoc_mode_concatenates_flow_names_in_identifier()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows qa lint --format=json --config=$this->configPath");
        $this->assertSame('qa+lint', $output['flow']);
        $this->assertSame(['qa', 'lint'], $output['flows']);
    }

    /** @test */
    public function ad_hoc_mode_dedups_shared_jobs()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows qa lint --format=json --config=$this->configPath");

        $names = array_column($output['jobs'], 'name');
        $this->assertSame(['job_a', 'job_b', 'job_c'], $names);
    }

    /** @test */
    public function declarative_mode_expands_meta_flow()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows ci-pack --format=json --config=$this->configPath");

        $this->assertSame('ci-pack', $output['flow']);
        $this->assertSame(['qa', 'lint'], $output['flows']);
        $this->assertSame(['job_a', 'job_b', 'job_c'], array_column($output['jobs'], 'name'));
    }

    /** @test */
    public function declarative_mode_applies_meta_flow_options_in_trace()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows ci-pack --format=json --config=$this->configPath");

        $this->assertSame(4, $output['effectiveOptions']['processes']['value']);
        $this->assertSame('flows.ci-pack.options', $output['effectiveOptions']['processes']['source']);
        $this->assertTrue($output['effectiveOptions']['failFast']['value']);
        $this->assertSame('flows.ci-pack.options', $output['effectiveOptions']['failFast']['source']);
    }

    /** @test */
    public function mixed_mode_ignores_meta_flow_options_and_emits_warning()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows ci-pack tests --format=json --config=$this->configPath");

        $this->assertSame('ci-pack+tests', $output['flow']);
        $this->assertSame(['qa', 'lint', 'tests'], $output['flows']);
        // meta-flow options ignored: source for processes/failFast must NOT be flows.ci-pack.options
        $this->assertNotSame('flows.ci-pack.options', $output['effectiveOptions']['processes']['source']);
    }

    /** @test */
    public function aborts_when_a_flow_name_is_unknown()
    {
        $this->buildMultiFlowFixture();

        $this->artisan("flows qa nope --config=$this->configPath")
            ->assertExitCode(1);

        $this->containsStringInOutput = ["Flow 'nope' is not defined", 'Available flows'];
    }

    /** @test */
    public function cli_processes_overrides_everything_in_trace()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows ci-pack --processes=12 --format=json --config=$this->configPath");

        $this->assertSame(12, $output['effectiveOptions']['processes']['value']);
        $this->assertSame('cli', $output['effectiveOptions']['processes']['source']);
    }

    /** @test */
    public function exclude_jobs_filters_the_merged_union()
    {
        $this->buildMultiFlowFixture();

        $output = $this->runJson("flows qa lint --exclude-jobs=job_b --format=json --config=$this->configPath");

        $names = array_column($output['jobs'], 'name');
        $this->assertSame(['job_a', 'job_c'], $names);
    }

    /**
     * Run a flows command with --format=json --output=tmpfile so we can decode the
     * structured payload from disk regardless of how Laravel-Zero buffers stdout.
     *
     * @return array<string, mixed>
     */
    private function runJson(string $command): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'flowsjson_');
        try {
            $this->artisan(trim("$command --output=$tmp"));
            $payload = (string) file_get_contents($tmp);
            $decoded = json_decode($payload, true);
            $this->assertIsArray($decoded, "Expected JSON output at $tmp, got:\n$payload");
            return $decoded;
        } finally {
            @unlink($tmp);
        }
    }
}
