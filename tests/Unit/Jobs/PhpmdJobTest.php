<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\PhpmdJob;

/**
 * Direct coverage for PhpmdJob. Infection report 2026-04-20 — L47, L67, L68, L91.
 */
class PhpmdJobTest extends TestCase
{
    /** @test */
    public function phpmd_is_a_supported_accelerable_job_type()
    {
        $registry = new JobRegistry();

        $this->assertTrue($registry->isSupported('phpmd'));
        $this->assertTrue($registry->isAccelerable('phpmd'));
    }

    /** @test */
    public function default_executable_is_phpmd()
    {
        $this->assertSame('phpmd', PhpmdJob::getDefaultExecutable());
    }

    /** @test */
    public function cache_paths_default_to_phpmd_cache_when_cache_file_is_absent()
    {
        // Mutant L47 CoalesceSwapFirstArg: `[] ?? '.phpmd.cache'` would always return '.phpmd.cache'
        // or vice-versa. Exact value check mata ambos extremos del swap.
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', ['paths' => ['src']]));

        $this->assertSame(['.phpmd.cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_honour_custom_cache_file_argument()
    {
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'paths'      => ['src'],
            'cache-file' => '.custom.cache',
        ]));

        $this->assertSame(['.custom.cache'], $job->getCachePaths());
    }

    /** @test */
    public function command_without_optional_flags_has_no_flag_tokens()
    {
        // Mutant L67 LogicalOr→And: the guard `!array_key_exists($key, $this->args) || empty(...)`
        // becomes `&&`, which would leak default flags. Ensure that an "empty args" command
        // never emits any ARGUMENT_MAP flag.
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'executablePath' => 'tools/phpmd',
            'paths'          => ['src'],
        ]));

        $command = $job->buildCommand();

        $this->assertStringNotContainsString('--cache=', $command);
        $this->assertStringNotContainsString('--cache ', $command);
        $this->assertStringNotContainsString('--exclude ', $command);
        $this->assertStringNotContainsString('--cache-file=', $command);
        $this->assertStringNotContainsString('--cache-strategy=', $command);
        $this->assertStringNotContainsString('--suffixes=', $command);
        $this->assertStringNotContainsString('--baseline-file=', $command);
    }

    /**
     * @test
     * Kills L67 LogicalOr→And in a different direction from the previous test:
     * when a key is PRESENT but has an empty value, the guard must still skip
     * it. With `&&`, only keys that are both absent AND empty are skipped —
     * keys present with empty values would leak empty flags.
     */
    public function command_skips_flags_whose_value_is_empty_string()
    {
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'executablePath' => 'tools/phpmd',
            'paths'          => ['src'],
            'cache-file'     => '',
            'suffixes'       => '',
        ]));

        $command = $job->buildCommand();

        $this->assertStringNotContainsString('--cache-file=', $command);
        $this->assertStringNotContainsString('--suffixes=', $command);
    }

    /** @test */
    public function command_keeps_processing_remaining_flags_after_skipping_an_empty_one()
    {
        // Mutant L68 Continue→break: the loop breaks on the first empty arg, so later
        // flags in ARGUMENT_MAP never appear. exclude is the FIRST key, so it being
        // absent + cache-file present proves that iteration continues past the skip.
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'executablePath' => 'tools/phpmd',
            'paths'          => ['src'],
            'cache-file'     => '.mycache',
            'suffixes'       => 'php,inc',
        ]));

        $command = $job->buildCommand();

        $this->assertStringNotContainsString('--exclude', $command);
        $this->assertStringContainsString('--cache-file=.mycache', $command);
        $this->assertStringContainsString('--suffixes=php,inc', $command);
    }

    /** @test */
    public function command_without_cli_extra_arguments_has_no_trailing_whitespace()
    {
        // Mutant L91 NotIdentical→Identical: `$this->cliExtraArguments !== ''` inverted,
        // causing the extra-args branch to run with an empty string → trailing space.
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'executablePath' => 'tools/phpmd',
            'paths'          => ['src'],
        ]));

        $command = $job->buildCommand();

        $this->assertStringEndsWith('cleancode,codesize,design,naming,unusedcode', $command);
        $this->assertSame($command, rtrim($command));
    }

    /**
     * @test
     * Kills L79 Assignment `.=`→`=` on the boolean branch of buildCommand:
     * a `cache => true` flag emitted via `$command = " --cache"` (with `=`)
     * would discard everything before the boolean. assertStringStartsWith
     * on the executable detects it.
     */
    public function command_with_boolean_flag_keeps_everything_before_the_flag()
    {
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'executablePath' => 'tools/phpmd',
            'paths'          => ['src'],
            'cache'          => true,
        ]));

        $command = $job->buildCommand();

        $this->assertStringStartsWith('tools/phpmd src ansi', $command);
        $this->assertStringContainsString(' --cache', $command);
    }

    /**
     * @test
     * Kills L92 Assignment `.=`→`=` on the cliExtraArguments branch: with `=`,
     * the command collapses to ' --reportfile=...' losing everything before.
     * assertStringStartsWith pins the executable prefix.
     */
    public function command_with_cli_extra_arguments_appends_them_with_single_space()
    {
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'executablePath' => 'tools/phpmd',
            'paths'          => ['src'],
        ]));
        $job->applyCliExtraArguments('--reportfile=reports/phpmd.txt');

        $command = $job->buildCommand();

        $this->assertStringStartsWith('tools/phpmd src', $command);
        $this->assertStringEndsWith(' --reportfile=reports/phpmd.txt', $command);
        $this->assertStringNotContainsString('  ', $command);
    }

    /** @test */
    public function command_uses_positional_order_executable_paths_format_rules()
    {
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'executablePath' => 'tools/phpmd',
            'paths'          => ['src', 'app'],
            'rules'          => 'qa/phpmd.xml',
        ]));

        $command = $job->buildCommand();

        $this->assertStringStartsWith('tools/phpmd src,app ansi qa/phpmd.xml', $command);
    }

    /** @test */
    public function apply_structured_output_format_switches_format_to_json()
    {
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'executablePath' => 'tools/phpmd',
            'paths'          => ['src'],
        ]));

        $this->assertTrue($job->supportsStructuredOutput());
        $this->assertTrue($job->applyStructuredOutputFormat());

        $command = $job->buildCommand();

        $this->assertStringStartsWith('tools/phpmd src json ', $command);
        $this->assertStringNotContainsString(' ansi ', $command);
    }

    /** @test */
    public function exclude_flag_emits_space_separated_csv_in_quotes()
    {
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'executablePath' => 'tools/phpmd',
            'paths'          => ['src'],
            'exclude'        => ['vendor', 'tools'],
        ]));

        $this->assertStringContainsString('--exclude "vendor,tools"', $job->buildCommand());
    }

    /** @test */
    public function boolean_cache_flag_appears_without_value_when_true()
    {
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'executablePath' => 'tools/phpmd',
            'paths'          => ['src'],
            'cache'          => true,
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString(' --cache', $command);
        $this->assertStringNotContainsString('--cache=', $command);
    }

    /** @test */
    public function does_not_expose_thread_capability()
    {
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', ['paths' => ['src']]));

        $this->assertNull($job->getThreadCapability());
    }
}
