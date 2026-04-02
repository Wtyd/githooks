<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\ParallelLintJob;
use Wtyd\GitHooks\Jobs\PhpcbfJob;
use Wtyd\GitHooks\Jobs\PhpcpdJob;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Jobs\PhpmdJob;
use Wtyd\GitHooks\Jobs\PhpstanJob;
use Wtyd\GitHooks\Jobs\PhpunitJob;
use Wtyd\GitHooks\Jobs\PsalmJob;
use Wtyd\GitHooks\Jobs\ScriptJob;

class JobBuildCommandTest extends TestCase
{
    /** @test */
    public function phpstan_builds_correct_command()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'executablePath' => 'vendor/bin/phpstan',
            'config'         => 'qa/phpstan.neon',
            'level'          => '8',
            'memory-limit'   => '1G',
            'no-progress'    => true,
            'paths'          => ['src'],
            'otherArguments' => '--ansi',
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString('vendor/bin/phpstan analyse', $command);
        $this->assertStringContainsString('-c qa/phpstan.neon', $command);
        $this->assertStringContainsString('-l 8', $command);
        $this->assertStringContainsString('--memory-limit=1G', $command);
        $this->assertStringContainsString('--no-progress', $command);
        $this->assertStringContainsString('--ansi', $command);
        $this->assertStringEndsWith('src', $command);
    }

    /** @test */
    public function phpstan_uses_default_executable()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths' => ['src'],
        ]));

        $command = $job->buildCommand();

        // Auto-detection: uses vendor/bin/phpstan if it exists, otherwise phpstan
        $this->assertRegExp('/^(vendor\/bin\/)?phpstan analyse/', $command);
    }

    /** @test */
    public function phpmd_builds_correct_positional_command()
    {
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'executablePath' => 'tools/php74/phpmd',
            'paths'          => ['src'],
            'rules'          => 'qa/phpmd-ruleset.xml',
            'exclude'        => ['vendor'],
        ]));

        $command = $job->buildCommand();

        // phpmd paths ansi rules --exclude
        $this->assertStringStartsWith('tools/php74/phpmd src ansi qa/phpmd-ruleset.xml', $command);
        $this->assertStringContainsString('--exclude "vendor"', $command);
    }

    /** @test */
    public function phpcs_builds_correct_command()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executablePath'   => 'tools/php74/phpcs',
            'standard'         => 'PSR12',
            'ignore'           => ['vendor', 'tools'],
            'error-severity'   => '1',
            'warning-severity' => '6',
            'paths'            => ['src'],
            'otherArguments'   => '--report=summary',
        ]));

        $command = $job->buildCommand();

        $this->assertStringStartsWith('tools/php74/phpcs', $command);
        $this->assertStringContainsString('--standard=PSR12', $command);
        $this->assertStringContainsString('--ignore=vendor,tools', $command);
        $this->assertStringContainsString('--error-severity=1', $command);
        $this->assertStringContainsString('--report=summary', $command);
        $this->assertStringEndsWith('src', $command);
    }

    /** @test */
    public function phpcbf_inherits_phpcs_and_detects_fix()
    {
        $job = new PhpcbfJob(new JobConfiguration('phpcbf_src', 'phpcbf', [
            'paths' => ['src'],
        ]));

        $this->assertRegExp('/^(vendor\/bin\/)?phpcbf/', $job->buildCommand());
        $this->assertTrue($job->isFixApplied(1));
        $this->assertFalse($job->isFixApplied(0));
    }

    /** @test */
    public function psalm_builds_key_value_and_boolean_args()
    {
        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', [
            'config'        => 'qa/psalm.xml',
            'memory-limit'  => '1G',
            'threads'       => '4',
            'no-diff'       => true,
            'paths'         => ['src', 'app'],
        ]));

        $command = $job->buildCommand();

        $this->assertRegExp('/^(vendor\/bin\/)?psalm/', $command);
        $this->assertStringContainsString('--config=qa/psalm.xml', $command);
        $this->assertStringContainsString('--memory-limit=1G', $command);
        $this->assertStringContainsString('--threads=4', $command);
        $this->assertStringContainsString('--no-diff', $command);
        $this->assertStringEndsWith('src app', $command);
    }

    /** @test */
    public function phpunit_builds_correct_command()
    {
        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', [
            'config'        => 'phpunit.xml',
            'group'         => 'unit',
            'exclude-group' => 'slow',
            'log-junit'     => 'junit.xml',
            'otherArguments' => '--colors=always',
        ]));

        $command = $job->buildCommand();

        $this->assertRegExp('/^(vendor\/bin\/)?phpunit/', $command);
        $this->assertStringContainsString('--group unit', $command);
        $this->assertStringContainsString('--exclude-group slow', $command);
        $this->assertStringContainsString('-c phpunit.xml', $command);
        $this->assertStringContainsString('--log-junit junit.xml', $command);
        $this->assertStringContainsString('--colors=always', $command);
    }

    /** @test */
    public function parallel_lint_builds_with_repeat_excludes()
    {
        $job = new ParallelLintJob(new JobConfiguration('lint', 'parallel-lint', [
            'paths'   => ['./'],
            'exclude' => ['vendor', 'tools'],
            'otherArguments' => '--colors',
        ]));

        $command = $job->buildCommand();

        $this->assertRegExp('/^(vendor\/bin\/)?parallel-lint/', $command);
        $this->assertStringContainsString('--exclude vendor', $command);
        $this->assertStringContainsString('--exclude tools', $command);
        $this->assertStringContainsString('--colors', $command);
        $this->assertStringEndsWith('./', $command);
    }

    /** @test */
    public function phpcpd_builds_with_repeat_excludes()
    {
        $job = new PhpcpdJob(new JobConfiguration('cpd', 'phpcpd', [
            'paths'   => ['./'],
            'exclude' => ['vendor', 'tests'],
        ]));

        $command = $job->buildCommand();

        $this->assertRegExp('/^(vendor\/bin\/)?phpcpd/', $command);
        $this->assertStringContainsString('--exclude vendor', $command);
        $this->assertStringContainsString('--exclude tests', $command);
    }

    /** @test */
    public function script_job_uses_executable_path()
    {
        $job = new ScriptJob(new JobConfiguration('my_script', 'script', [
            'executablePath' => 'vendor/bin/my-tool',
            'otherArguments' => '--verbose',
        ]));

        $this->assertEquals('vendor/bin/my-tool --verbose', $job->buildCommand());
    }

    /** @test */
    public function custom_job_runs_script_verbatim()
    {
        $job = new CustomJob(new JobConfiguration('lint_js', 'custom', [
            'script' => 'npm run lint -- --fix',
        ]));

        $this->assertEquals('npm run lint -- --fix', $job->buildCommand());
    }

    /** @test */
    public function job_exposes_ignore_errors_and_fail_fast()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'ignoreErrorsOnExit' => true,
            'failFast'           => true,
            'paths'              => ['src'],
        ]));

        $this->assertTrue($job->isIgnoreErrorsOnExit());
        $this->assertTrue($job->isFailFast());
    }

    /** @test */
    public function job_registry_creates_correct_types()
    {
        $registry = new JobRegistry();

        $this->assertTrue($registry->isSupported('phpstan'));
        $this->assertTrue($registry->isSupported('custom'));
        $this->assertFalse($registry->isSupported('nonexistent'));

        $job = $registry->create(new JobConfiguration('test', 'phpstan', ['paths' => ['src']]));
        $this->assertInstanceOf(PhpstanJob::class, $job);

        $job = $registry->create(new JobConfiguration('test', 'custom', ['script' => 'echo ok']));
        $this->assertInstanceOf(CustomJob::class, $job);
    }
}
