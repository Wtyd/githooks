<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\PhpCsFixerJob;

class PhpCsFixerJobTest extends TestCase
{
    /** @test */
    public function php_cs_fixer_is_a_supported_job_type()
    {
        $this->assertTrue((new JobRegistry())->isSupported('php-cs-fixer'));
    }

    /** @test */
    public function php_cs_fixer_builds_correct_command_with_all_arguments()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'executablePath' => 'vendor/bin/php-cs-fixer',
            'config'         => '.php-cs-fixer.dist.php',
            'rules'          => '@PSR12',
            'dry-run'        => true,
            'diff'           => true,
            'allow-risky'    => 'yes',
            'using-cache'    => 'no',
            'cache-file'     => '.cache/fixer',
            'paths'          => ['src'],
            'otherArguments' => '--ansi',
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString('vendor/bin/php-cs-fixer fix', $command);
        $this->assertStringContainsString('--config=.php-cs-fixer.dist.php', $command);
        $this->assertStringContainsString('--rules=@PSR12', $command);
        $this->assertStringContainsString('--dry-run', $command);
        $this->assertStringContainsString('--show-diff', $command);
        $this->assertStringContainsString('--allow-risky=yes', $command);
        $this->assertStringContainsString('--using-cache=no', $command);
        $this->assertStringContainsString('--cache-file=.cache/fixer', $command);
        $this->assertStringContainsString('--ansi', $command);
        $this->assertStringEndsWith('src', $command);
    }

    /** @test */
    public function php_cs_fixer_uses_default_executable_when_executable_path_is_empty()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths' => ['src'],
        ]));

        $command = $job->buildCommand();

        $this->assertMatchesRegularExpression('/^(vendor\/bin\/)?php-cs-fixer fix/', $command);
    }

    /** @test */
    public function php_cs_fixer_includes_dry_run_flag()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'executablePath' => 'vendor/bin/php-cs-fixer',
            'dry-run'        => true,
            'paths'          => ['src'],
        ]));

        $this->assertStringContainsString('--dry-run', $job->buildCommand());
    }

    /** @test */
    public function php_cs_fixer_includes_using_cache_and_cache_file()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'executablePath' => 'vendor/bin/php-cs-fixer',
            'using-cache'    => 'no',
            'cache-file'     => '.php-cs-fixer.cache',
            'paths'          => ['src'],
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString('--using-cache=no', $command);
        $this->assertStringContainsString('--cache-file=.php-cs-fixer.cache', $command);
    }

    /** @test */
    public function php_cs_fixer_runs_against_several_paths()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'executablePath' => 'vendor/bin/php-cs-fixer',
            'paths'          => ['src', 'app'],
        ]));

        $this->assertStringEndsWith('src app', $job->buildCommand());
    }

    /** @test */
    public function php_cs_fixer_ignores_unexpected_arguments()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'executablePath'     => 'vendor/bin/php-cs-fixer',
            'paths'              => ['src'],
            'unexpected_arg'     => 'value',
            'another_bad_key'    => true,
        ]));

        $command = $job->buildCommand();

        $this->assertStringNotContainsString('unexpected_arg', $command);
        $this->assertStringNotContainsString('another_bad_key', $command);
    }

    /** @test */
    public function php_cs_fixer_detects_fix_applied_on_success()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths' => ['src'],
        ]));

        $this->assertTrue($job->isFixApplied(0));
    }

    /** @test */
    public function php_cs_fixer_does_not_detect_fix_in_dry_run_mode()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'dry-run' => true,
            'paths'   => ['src'],
        ]));

        $this->assertFalse($job->isFixApplied(0));
    }

    /** @test */
    public function php_cs_fixer_does_not_detect_fix_on_error_exit_code()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths' => ['src'],
        ]));

        $this->assertFalse($job->isFixApplied(1));
        $this->assertFalse($job->isFixApplied(4));
        $this->assertFalse($job->isFixApplied(8));
    }

    /** @test */
    public function php_cs_fixer_returns_cache_paths()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths' => ['src'],
        ]));

        $this->assertEquals(['.php-cs-fixer.cache'], $job->getCachePaths());
    }

    /** @test */
    public function php_cs_fixer_has_no_thread_capability()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths' => ['src'],
        ]));

        $this->assertNull($job->getThreadCapability());
    }

    /** @test */
    public function php_cs_fixer_is_accelerable()
    {
        $this->assertTrue((new JobRegistry())->isAccelerable('php-cs-fixer'));
    }

    /** @test */
    public function php_cs_fixer_with_executable_prefix()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'executablePath' => 'vendor/bin/php-cs-fixer',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/php-cs-fixer fix', $job->buildCommand());
    }

    /** @test */
    public function php_cs_fixer_with_cli_extra_arguments()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'executablePath' => 'vendor/bin/php-cs-fixer',
            'paths'          => ['src'],
        ]));
        $job->applyCliExtraArguments('--show-progress=dots');

        $command = $job->buildCommand();

        $this->assertStringContainsString('--show-progress=dots', $command);
        $this->assertStringEndsWith('src', $command);
    }
}
