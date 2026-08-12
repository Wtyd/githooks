<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\PintJob;

/**
 * Factor table — verdict semantics (mayApplyFixes / isFixApplied).
 *
 * Factors with power over the output:
 *   - `test` ∈ {absent, false, true} — absent and explicit false are distinct
 *     classes: both must behave as fix mode, but they take different paths
 *     through the args array (missing key vs falsy value).
 *   - exit code ∈ {0, 1} — 1 covers both `--test` style issues and fix-mode
 *     parse errors (verified against Pint 1.30: fix mode exits 0 even when it
 *     applied fixes; only parse errors make it exit 1).
 *
 * | test   | exit | mayApplyFixes | isFixApplied |
 * |--------|------|---------------|--------------|
 * | absent | 0    | true          | true         |
 * | absent | 1    | true          | false        |
 * | false  | 0    | true          | true         |
 * | false  | 1    | true          | false        |
 * | true   | 0    | false         | false        |
 * | true   | 1    | false         | false        |
 */
class PintJobTest extends UnitTestCase
{
    /** @test */
    public function pint_is_a_supported_job_type()
    {
        $this->assertTrue((new JobRegistry())->isSupported('pint'));
    }

    /** @test */
    public function pint_builds_correct_command_with_all_arguments()
    {
        $job = new PintJob(new JobConfiguration('pint_app', 'pint', [
            'executable-path' => 'vendor/bin/pint',
            'config'          => 'pint.json',
            'test'            => true,
            'paths'           => ['app', 'tests'],
            'other-arguments' => '--ansi',
        ]));

        $this->assertSame('vendor/bin/pint --config=pint.json --test --ansi app tests', $job->buildCommand());
    }

    /** @test */
    public function pint_uses_default_executable_when_executable_path_is_empty()
    {
        $job = new PintJob(new JobConfiguration('pint_app', 'pint', [
            'paths' => ['app'],
        ]));

        $this->assertMatchesRegularExpression('/^(vendor\/bin\/)?pint /', $job->buildCommand());
    }

    /** @test */
    public function pint_has_no_subcommand()
    {
        $job = new PintJob(new JobConfiguration('pint_app', 'pint', [
            'executable-path' => 'vendor/bin/pint',
            'paths'           => ['app'],
        ]));

        $this->assertSame('vendor/bin/pint app', $job->buildCommand());
    }

    /** @test */
    public function pint_omits_the_test_flag_when_test_is_explicitly_false()
    {
        $job = new PintJob(new JobConfiguration('pint_app', 'pint', [
            'executable-path' => 'vendor/bin/pint',
            'test'            => false,
            'paths'           => ['app'],
        ]));

        $this->assertSame('vendor/bin/pint app', $job->buildCommand());
    }

    /** @test */
    public function pint_ignores_unexpected_arguments()
    {
        $job = new PintJob(new JobConfiguration('pint_app', 'pint', [
            'executable-path' => 'vendor/bin/pint',
            'paths'           => ['app'],
            'unexpected_arg'  => 'value',
            'another_bad_key' => true,
        ]));

        $command = $job->buildCommand();

        $this->assertStringNotContainsString('unexpected_arg', $command);
        $this->assertStringNotContainsString('another_bad_key', $command);
    }

    /**
     * @test
     *
     * Covers the whole verdict decision table (see the class docblock).
     *
     * @dataProvider verdictDecisionTable
     * @param array<string, mixed> $extraArgs
     */
    public function pint_verdict_follows_the_test_mode_and_exit_code(
        array $extraArgs,
        int $exitCode,
        bool $expectedMayApplyFixes,
        bool $expectedFixApplied,
        string $scenario
    ): void {
        $job = new PintJob(new JobConfiguration('pint_app', 'pint', array_merge(
            ['paths' => ['app']],
            $extraArgs
        )));

        $this->assertSame($expectedMayApplyFixes, $job->mayApplyFixes(), "mayApplyFixes — $scenario");
        $this->assertSame($expectedFixApplied, $job->isFixApplied($exitCode), "isFixApplied — $scenario");
    }

    /** @return array<string, array{array<string, mixed>, int, bool, bool, string}> */
    public function verdictDecisionTable(): array
    {
        return [
            'test absent, exit 0 — fix ran, re-stage'         => [[], 0, true, true, 'fix mode success'],
            'test absent, exit 1 — parse error, no re-stage'  => [[], 1, true, false, 'fix mode failure'],
            'test false, exit 0 — same as absent'             => [['test' => false], 0, true, true, 'explicit fix mode success'],
            'test false, exit 1 — same as absent'             => [['test' => false], 1, true, false, 'explicit fix mode failure'],
            'test true, exit 0 — clean check, nothing fixed'  => [['test' => true], 0, false, false, 'check mode pass'],
            'test true, exit 1 — dirty check, nothing fixed'  => [['test' => true], 1, false, false, 'check mode fail'],
        ];
    }

    /**
     * @test
     *
     * Pint keeps its cache in the system temp dir, never in the project:
     * nothing to declare for cache:clear.
     */
    public function pint_declares_no_cache_paths()
    {
        $job = new PintJob(new JobConfiguration('pint_app', 'pint', [
            'paths' => ['app'],
        ]));

        $this->assertSame([], $job->getCachePaths());
        $this->assertNull($job->getCacheResolutionWarning());
    }

    /** @test */
    public function pint_has_no_thread_capability()
    {
        $job = new PintJob(new JobConfiguration('pint_app', 'pint', [
            'paths' => ['app'],
        ]));

        $this->assertNull($job->getThreadCapability());
    }

    /** @test */
    public function pint_is_accelerable()
    {
        $this->assertTrue((new JobRegistry())->isAccelerable('pint'));
    }

    /** @test */
    public function pint_with_executable_prefix()
    {
        $job = new PintJob(new JobConfiguration('pint_app', 'pint', [
            'executable-path' => 'vendor/bin/pint',
            'paths'           => ['app'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/pint', $job->buildCommand());
    }

    /** @test */
    public function pint_with_cli_extra_arguments()
    {
        $job = new PintJob(new JobConfiguration('pint_app', 'pint', [
            'executable-path' => 'vendor/bin/pint',
            'paths'           => ['app'],
        ]));
        $job->applyCliExtraArguments('--test');

        $this->assertSame('vendor/bin/pint --test app', $job->buildCommand());
    }
}
