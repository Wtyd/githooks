<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Jobs\JobRegistry;

class CustomJobTest extends UnitTestCase
{
    /** @test */
    public function custom_is_a_supported_job_type()
    {
        $this->assertTrue((new JobRegistry())->isSupported('custom'));
    }

    /** @test */
    public function custom_is_not_accelerable_by_default()
    {
        $this->assertFalse((new JobRegistry())->isAccelerable('custom'));
    }

    /**
     * Decision table of the `re-stage` guard (F1 `re-stage` × F2 exit code),
     * plus non-bool coercion. Anchors FEAT-23: only `re-stage` truthy AND a
     * zero exit code count as a fix; a non-zero exit never re-stages.
     *
     * @test
     * @dataProvider fixAppliedScenarios
     *
     * @param array<string, mixed> $extra
     */
    public function custom_is_fix_applied_honours_re_stage_and_exit_code(array $extra, int $exitCode, bool $expected)
    {
        $job = new CustomJob(new JobConfiguration('audit', 'custom', array_merge([
            'script' => 'true',
        ], $extra)));

        $this->assertSame($expected, $job->isFixApplied($exitCode));
    }

    /** @return array<string, array{array<string, mixed>, int, bool}> */
    public function fixAppliedScenarios(): array
    {
        return [
            're-stage ausente + exit 0'         => [[], 0, false],
            're-stage ausente + exit 1'         => [[], 1, false],
            're-stage:false + exit 0'           => [['re-stage' => false], 0, false],
            're-stage:false + exit 1'           => [['re-stage' => false], 1, false],
            're-stage:true + exit 0'            => [['re-stage' => true], 0, true],
            're-stage:true + exit 1'            => [['re-stage' => true], 1, false],
            're-stage:true + exit 2'            => [['re-stage' => true], 2, false],
            're-stage no-bool truthy + exit 0'  => [['re-stage' => 'yes'], 0, true],
            're-stage int 0 (falsy) + exit 0'   => [['re-stage' => 0], 0, false],
            're-stage int 1 (truthy) + exit 0'  => [['re-stage' => 1], 0, true],
        ];
    }

    /** @test */
    public function re_stage_flag_does_not_leak_into_the_built_command()
    {
        $job = new CustomJob(new JobConfiguration('formateo', 'custom', [
            'script'   => 'vendor/bin/pint',
            're-stage' => true,
        ]));

        $command = $job->buildCommand();

        $this->assertSame('vendor/bin/pint', $command);
        $this->assertStringNotContainsString('re-stage', $command);
    }
}
