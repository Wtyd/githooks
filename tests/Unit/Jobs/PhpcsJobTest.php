<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ThreadCapability;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\PhpcsJob;

/**
 * Direct coverage for PhpcsJob. Infection report 2026-04-20 — L47-50.
 */
class PhpcsJobTest extends TestCase
{
    /** @test */
    public function phpcs_is_a_supported_accelerable_job_type()
    {
        $registry = new JobRegistry();

        $this->assertTrue($registry->isSupported('phpcs'));
        $this->assertTrue($registry->isSupported('phpcbf'));
        $this->assertTrue($registry->isAccelerable('phpcs'));
    }

    /** @test */
    public function default_executable_is_phpcs()
    {
        $this->assertSame('phpcs', PhpcsJob::getDefaultExecutable());
    }

    /** @test */
    public function cache_paths_default_to_phpcs_cache()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]));

        $this->assertSame(['.phpcs.cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_honour_cache_argument_string_value()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths' => ['src'],
            'cache' => 'storage/phpcs.cache',
        ]));

        $this->assertSame(['storage/phpcs.cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_ignore_boolean_cache_argument_and_keep_default()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths' => ['src'],
            'cache' => true,
        ]));

        $this->assertSame(['.phpcs.cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_read_cache_arg_from_ruleset_xml()
    {
        $rulesetPath = sys_get_temp_dir() . '/phpcs-ruleset-' . uniqid() . '.xml';
        file_put_contents($rulesetPath, <<<'XML'
<?xml version="1.0"?>
<ruleset name="Test">
    <description>Test ruleset</description>
    <arg name="cache" value="qa/phpcs.cache"/>
    <rule ref="PSR12"/>
</ruleset>
XML);

        try {
            $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
                'paths'    => ['src'],
                'standard' => $rulesetPath,
            ]));

            $this->assertSame(['qa/phpcs.cache'], $job->getCachePaths());
        } finally {
            unlink($rulesetPath);
        }
    }

    /** @test */
    public function cache_paths_fall_back_to_default_when_ruleset_has_no_cache_arg()
    {
        $rulesetPath = sys_get_temp_dir() . '/phpcs-ruleset-' . uniqid() . '.xml';
        file_put_contents($rulesetPath, <<<'XML'
<?xml version="1.0"?>
<ruleset name="Test">
    <rule ref="PSR12"/>
</ruleset>
XML);

        try {
            $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
                'paths'    => ['src'],
                'standard' => $rulesetPath,
            ]));

            $this->assertSame(['.phpcs.cache'], $job->getCachePaths());
        } finally {
            unlink($rulesetPath);
        }
    }

    /** @test */
    public function whitespace_only_cache_arg_falls_back_to_default()
    {
        // Adversarial: '   ' is not a real path; must fall back, not delete '   '.
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths' => ['src'],
            'cache' => '   ',
        ]));

        $this->assertSame(['.phpcs.cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_arg_with_leading_or_trailing_whitespace_is_trimmed()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths' => ['src'],
            'cache' => '  qa/phpcs.cache  ',
        ]));

        $this->assertSame(['qa/phpcs.cache'], $job->getCachePaths());
    }

    /** @test */
    public function multiple_cache_args_in_ruleset_pick_the_last_one()
    {
        // Adversarial: phpcs itself last-wins on duplicated args; we mirror it.
        $rulesetPath = sys_get_temp_dir() . '/phpcs-multi-' . uniqid() . '.xml';
        file_put_contents($rulesetPath, <<<'XML'
<?xml version="1.0"?>
<ruleset name="Test">
    <arg name="cache" value="first.cache"/>
    <arg name="cache" value="second.cache"/>
</ruleset>
XML);

        try {
            $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
                'paths'    => ['src'],
                'standard' => $rulesetPath,
            ]));

            $this->assertSame(['second.cache'], $job->getCachePaths());
        } finally {
            unlink($rulesetPath);
        }
    }

    /** @test */
    public function cache_arg_in_ruleset_is_skipped_when_value_is_empty_then_falls_back()
    {
        // Adversarial: <arg name="cache" value=""/> alone → default.
        $rulesetPath = sys_get_temp_dir() . '/phpcs-empty-' . uniqid() . '.xml';
        file_put_contents($rulesetPath, <<<'XML'
<?xml version="1.0"?>
<ruleset name="Test">
    <arg name="cache" value=""/>
</ruleset>
XML);

        try {
            $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
                'paths'    => ['src'],
                'standard' => $rulesetPath,
            ]));

            $this->assertSame(['.phpcs.cache'], $job->getCachePaths());
        } finally {
            unlink($rulesetPath);
        }
    }

    /** @test */
    public function malformed_ruleset_does_not_crash_falls_back_to_default()
    {
        $rulesetPath = sys_get_temp_dir() . '/phpcs-bad-' . uniqid() . '.xml';
        file_put_contents($rulesetPath, '<?xml version="1.0"?><ruleset><arg name="cache" value="x"');

        try {
            $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
                'paths'    => ['src'],
                'standard' => $rulesetPath,
            ]));

            $this->assertSame(['.phpcs.cache'], $job->getCachePaths());
        } finally {
            unlink($rulesetPath);
        }
    }

    /** @test */
    public function cache_arg_in_job_takes_precedence_over_ruleset()
    {
        $rulesetPath = sys_get_temp_dir() . '/phpcs-ruleset-' . uniqid() . '.xml';
        file_put_contents($rulesetPath, <<<'XML'
<?xml version="1.0"?>
<ruleset name="Test">
    <arg name="cache" value="ruleset/phpcs.cache"/>
</ruleset>
XML);

        try {
            $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
                'paths'    => ['src'],
                'standard' => $rulesetPath,
                'cache'    => 'job/phpcs.cache',
            ]));

            $this->assertSame(['job/phpcs.cache'], $job->getCachePaths());
        } finally {
            unlink($rulesetPath);
        }
    }

    /** @test */
    public function thread_capability_defaults_to_one_thread_when_parallel_is_absent()
    {
        // Mutants L49: DecrementInteger (1→0) / IncrementInteger (1→2) on the default.
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]));

        $cap = $job->getThreadCapability();

        $this->assertInstanceOf(ThreadCapability::class, $cap);
        $this->assertSame(1, $cap->getDefaultThreads());
        $this->assertSame('parallel', $cap->getArgumentKey());
    }

    /** @test */
    public function thread_capability_reads_parallel_value_as_integer()
    {
        // Mutant L49 CastInt: `(int) $this->args['parallel']` — string '8' must end up as int 8.
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths'    => ['src'],
            'parallel' => '8',
        ]));

        $cap = $job->getThreadCapability();

        $this->assertInstanceOf(ThreadCapability::class, $cap);
        $this->assertSame(8, $cap->getDefaultThreads());
    }

    /** @test */
    public function thread_capability_is_a_new_object_not_null()
    {
        // Mutant L50 NewObject: replacing `new ThreadCapability(...)` with `null`.
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]));

        $this->assertInstanceOf(ThreadCapability::class, $job->getThreadCapability());
    }

    /** @test */
    public function apply_thread_limit_propagates_value_into_command()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executable-path' => 'vendor/bin/phpcs',
            'paths'          => ['src'],
        ]));

        $job->applyThreadLimit(4);

        $this->assertStringContainsString('--parallel=4', $job->buildCommand());
    }

    /** @test */
    public function apply_thread_limit_overrides_existing_parallel_value()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executable-path' => 'vendor/bin/phpcs',
            'paths'          => ['src'],
            'parallel'       => 8,
        ]));

        $job->applyThreadLimit(2);

        $command = $job->buildCommand();

        $this->assertStringContainsString('--parallel=2', $command);
        $this->assertStringNotContainsString('--parallel=8', $command);
    }

    /** @test */
    public function supports_structured_output_returns_true()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]));

        $this->assertTrue($job->supportsStructuredOutput());
    }

    /** @test */
    public function apply_structured_output_format_sets_report_to_json()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executable-path' => 'vendor/bin/phpcs',
            'paths'          => ['src'],
        ]));

        $applied = $job->applyStructuredOutputFormat();

        $this->assertTrue($applied);
        $this->assertStringContainsString('--report=json', $job->buildCommand());
    }

    /** @test */
    public function command_ends_with_paths_without_trailing_whitespace()
    {
        // Guards that buildCommand() doesn't produce trailing spaces when optional
        // fields are absent (protects against PhpmdJob:91 style mutants on siblings).
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executable-path' => 'vendor/bin/phpcs',
            'paths'          => ['src', 'app'],
        ]));

        $this->assertStringEndsWith('src app', $job->buildCommand());
    }

    /**
     * BUG-1: PHPCS may filter out every input file when `--ignore` (CLI) or
     * `<exclude-pattern>` (ruleset) covers all of them. Exit code varies by
     * version (3.13.x: 0 silent; older / fork: 16) and so does the marker
     * ("All specified files were excluded" vs "No files were checked"). Both
     * are accepted defensively; the marker check is what decides.
     *
     * @dataProvider emptyInputToleranceProvider
     */
    public function test_empty_input_tolerance_matches_phpcs_exit_signature(
        int $exitCode,
        string $output,
        bool $expected,
        string $scenario
    ): void {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]));

        $this->assertSame(
            $expected,
            $job->isEmptyInputTolerated($exitCode, $output),
            "Scenario: $scenario"
        );
    }

    /** @return array<string, array{int, string, bool, string}> */
    public function emptyInputToleranceProvider(): array
    {
        $excluded = 'All specified files were excluded';
        $noFiles  = 'No files were checked';

        return [
            'exit=16 + "All specified files were excluded" (legacy / fork)' => [
                16,
                "ERROR: $excluded or did not match filtering rules.\n",
                true,
                'cliente bug PROD-4492 — PHPCS legacy emits 16',
            ],
            'exit=16 + "No files were checked"' => [
                16,
                "ERROR: $noFiles.\n",
                true,
                'alternate marker observed across versions',
            ],
            'exit=1 + marker (defensive)' => [
                1,
                "ERROR: $excluded.\n",
                true,
                'exit=1 is included in the defensive set so future versions do not silently regress',
            ],
            'exit=2 + marker (defensive)' => [
                2,
                "ERROR: $excluded.\n",
                true,
                'exit=2 (warnings level) accepted defensively when marker present',
            ],
            'exit=3 + marker (defensive)' => [
                3,
                "ERROR: $excluded.\n",
                true,
                'exit=3 (errors+warnings) accepted defensively when marker present',
            ],
            'exit=1 + real violations without marker' => [
                1,
                "FILE: src/Foo.php\n----\nFOUND 9 ERRORS AFFECTING 2 LINES\n",
                false,
                'real failure must NOT be reinterpreted as skipped',
            ],
            'exit=0 + marker (defensive)' => [
                0,
                "$excluded",
                false,
                'success exit code never reinterpreted',
            ],
            'exit=4 + marker outside defensive set' => [
                4,
                "$excluded",
                false,
                'exit codes outside {1,2,3,16} are treated as real failures',
            ],
            'exit=16 + empty output' => [
                16,
                '',
                false,
                'no marker present — likely a different "exit 16" failure mode',
            ],
            'exit=16 + marker case mismatch' => [
                16,
                'all specified files were excluded',
                false,
                'matcher is intentionally case-sensitive — phpcs emits the marker verbatim',
            ],
            'exit=16 + marker as substring of longer text' => [
                16,
                "Some prefix... $excluded or did not match filtering rules. Suffix.",
                true,
                'str_contains tolerates surrounding context',
            ],
        ];
    }

    // ========================================================================
    // Mutation testing Tier 3 — pin the composite guard on the standard arg,
    // the is_file/is_readable guard on the ruleset path, and the trim() on
    // the <arg value> attribute.
    // ========================================================================

    /**
     * @test
     *
     * Kills:
     *   - L69 LogicalAnd `is_string($standard) && $standard !== ''`
     *
     * A non-string `standard` value (e.g. accidentally an array via legacy
     * deep-config) must NOT crash and must fall back to the default cache
     * path — the is_string guard short-circuits the ruleset parsing.
     */
    public function standard_arg_non_string_falls_back_to_default_cache(): void
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths'    => ['src'],
            'standard' => ['PSR12'], // legacy "array of standards" — not allowed but tolerated
        ]));

        $this->assertSame(['.phpcs.cache'], $job->getCachePaths());
    }

    /**
     * Companion: empty-string standard also short-circuits (kills the
     * `$standard !== ''` branch of the same LogicalAnd).
     *
     * @test
     */
    public function standard_arg_empty_string_falls_back_to_default_cache(): void
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'paths'    => ['src'],
            'standard' => '',
        ]));

        $this->assertSame(['.phpcs.cache'], $job->getCachePaths());
    }

    /**
     * @test
     *
     * Kills:
     *   - L80 LogicalOr `!is_file($rulesetPath) || !is_readable($rulesetPath)`
     *
     * Ruleset file exists but is unreadable: extractCacheFromRuleset must
     * return null and the job must fall back to the default cache. The
     * mutant `&&` would only short-circuit when BOTH guards are true at the
     * same time — a readable, present file — and would proceed to parse an
     * unreadable file, which raises a simplexml warning and corrupts the
     * result.
     */
    public function unreadable_ruleset_falls_back_to_default_cache(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses chmod permission checks');
        }

        $rulesetPath = sys_get_temp_dir() . '/phpcs-unreadable-' . uniqid() . '.xml';
        file_put_contents($rulesetPath, <<<'XML'
<?xml version="1.0"?>
<ruleset><arg name="cache" value="from-unreadable.cache"/></ruleset>
XML);
        chmod($rulesetPath, 0000);

        try {
            $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
                'paths'    => ['src'],
                'standard' => $rulesetPath,
            ]));

            $this->assertSame(['.phpcs.cache'], $job->getCachePaths());
        } finally {
            chmod($rulesetPath, 0644);
            unlink($rulesetPath);
        }
    }

    /**
     * @test
     *
     * Kills:
     *   - L95 UnwrapTrim `trim((string) $arg['value'])` → `(string) $arg['value']`
     *
     * Ruleset's `<arg value>` may carry leading/trailing whitespace
     * (typical when an editor wraps the attribute). Without trim, the
     * resolved cache path becomes "  with-spaces.cache  ", which the disk
     * would never expose.
     */
    public function ruleset_arg_value_is_trimmed_of_surrounding_whitespace(): void
    {
        $rulesetPath = sys_get_temp_dir() . '/phpcs-trim-' . uniqid() . '.xml';
        file_put_contents($rulesetPath, <<<'XML'
<?xml version="1.0"?>
<ruleset><arg name="cache" value="  trimmed.cache  "/></ruleset>
XML);

        try {
            $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
                'paths'    => ['src'],
                'standard' => $rulesetPath,
            ]));

            $this->assertSame(['trimmed.cache'], $job->getCachePaths());
        } finally {
            unlink($rulesetPath);
        }
    }
}
