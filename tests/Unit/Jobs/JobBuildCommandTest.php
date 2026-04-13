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
use Wtyd\GitHooks\Jobs\PhpCsFixerJob;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Jobs\PhpmdJob;
use Wtyd\GitHooks\Jobs\PhpstanJob;
use Wtyd\GitHooks\Jobs\PhpunitJob;
use Wtyd\GitHooks\Jobs\PsalmJob;
use Wtyd\GitHooks\Jobs\RectorJob;
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
        $this->assertMatchesRegularExpression('/^(vendor\/bin\/)?phpstan analyse/', $command);
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

        $this->assertMatchesRegularExpression('/^(vendor\/bin\/)?phpcbf/', $job->buildCommand());
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

        $this->assertMatchesRegularExpression('/^(vendor\/bin\/)?psalm/', $command);
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

        $this->assertMatchesRegularExpression('/^(vendor\/bin\/)?phpunit/', $command);
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

        $this->assertMatchesRegularExpression('/^(vendor\/bin\/)?parallel-lint/', $command);
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

        $this->assertMatchesRegularExpression('/^(vendor\/bin\/)?phpcpd/', $command);
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
        $this->assertTrue($registry->isSupported('php-cs-fixer'));
        $this->assertTrue($registry->isSupported('rector'));
        $this->assertFalse($registry->isSupported('nonexistent'));

        $job = $registry->create(new JobConfiguration('test', 'phpstan', ['paths' => ['src']]));
        $this->assertInstanceOf(PhpstanJob::class, $job);

        $job = $registry->create(new JobConfiguration('test', 'custom', ['script' => 'echo ok']));
        $this->assertInstanceOf(CustomJob::class, $job);

        $job = $registry->create(new JobConfiguration('test', 'php-cs-fixer', ['paths' => ['src']]));
        $this->assertInstanceOf(PhpCsFixerJob::class, $job);

        $job = $registry->create(new JobConfiguration('test', 'rector', ['paths' => ['src']]));
        $this->assertInstanceOf(RectorJob::class, $job);
    }

    /** @test */
    public function custom_job_with_paths_builds_structured_command()
    {
        $job = new CustomJob(new JobConfiguration('eslint_src', 'custom', [
            'executablePath' => 'eslint',
            'paths' => ['src'],
            'otherArguments' => '--fix',
        ]));

        $this->assertEquals('eslint src --fix', $job->buildCommand());
    }

    /** @test */
    public function custom_job_with_paths_and_filtered_files()
    {
        $job = new CustomJob(new JobConfiguration('eslint_src', 'custom', [
            'executablePath' => 'eslint',
            'paths' => ['src/Foo.php', 'src/Bar.php'],
            'otherArguments' => '--fix',
        ]));

        $this->assertEquals('eslint src/Foo.php src/Bar.php --fix', $job->buildCommand());
    }

    /** @test */
    public function custom_job_without_paths_uses_script_verbatim()
    {
        $job = new CustomJob(new JobConfiguration('full_cmd', 'custom', [
            'script' => 'eslint src --fix',
        ]));

        $this->assertEquals('eslint src --fix', $job->buildCommand());
    }

    /** @test */
    public function job_registry_reports_accelerable_types()
    {
        $registry = new JobRegistry();

        $this->assertTrue($registry->isAccelerable('phpstan'));
        $this->assertTrue($registry->isAccelerable('phpcs'));
        $this->assertTrue($registry->isAccelerable('phpcbf'));
        $this->assertTrue($registry->isAccelerable('phpmd'));
        $this->assertTrue($registry->isAccelerable('parallel-lint'));
        $this->assertTrue($registry->isAccelerable('psalm'));
        $this->assertTrue($registry->isAccelerable('php-cs-fixer'));
        $this->assertTrue($registry->isAccelerable('rector'));

        $this->assertFalse($registry->isAccelerable('phpunit'));
        $this->assertFalse($registry->isAccelerable('phpcpd'));
        $this->assertFalse($registry->isAccelerable('script'));
        $this->assertFalse($registry->isAccelerable('custom'));
        $this->assertFalse($registry->isAccelerable('nonexistent'));
    }

    /** @test */
    public function job_configuration_accelerable_uses_type_default()
    {
        $registry = new JobRegistry();

        $phpstanConfig = new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]);
        $this->assertTrue($phpstanConfig->isAccelerable($registry));

        $phpunitConfig = new JobConfiguration('tests', 'phpunit', []);
        $this->assertFalse($phpunitConfig->isAccelerable($registry));

        $customConfig = new JobConfiguration('lint', 'custom', ['script' => 'echo ok']);
        $this->assertFalse($customConfig->isAccelerable($registry));
    }

    /** @test */
    public function job_configuration_accelerable_explicit_overrides_default()
    {
        $registry = new JobRegistry();

        $phpstanDisabled = new JobConfiguration('phpstan_src', 'phpstan', [
            'paths' => ['src'],
            'accelerable' => false,
        ]);
        $this->assertFalse($phpstanDisabled->isAccelerable($registry));

        $customEnabled = new JobConfiguration('lint', 'custom', [
            'executablePath' => 'eslint',
            'paths' => ['src'],
            'accelerable' => true,
        ]);
        $this->assertTrue($customEnabled->isAccelerable($registry));
    }

    /** @test */
    public function job_configuration_with_paths_returns_new_instance()
    {
        $original = new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src'], 'level' => 0]);
        $modified = $original->withPaths(['src/Foo.php', 'src/Bar.php']);

        $this->assertEquals(['src'], $original->getPaths());
        $this->assertEquals(['src/Foo.php', 'src/Bar.php'], $modified->getPaths());
        $this->assertNotSame($original, $modified);
    }

    // ========================================================================
    // executable-prefix
    // ========================================================================

    /** @test */
    public function executable_prefix_is_prepended_to_phpstan_command()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'executablePath' => 'vendor/bin/phpstan',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpstan analyse', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_to_phpcs_command()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executablePath' => 'vendor/bin/phpcs',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpcs', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_to_phpcbf_command()
    {
        $job = new PhpcbfJob(new JobConfiguration('phpcbf_src', 'phpcbf', [
            'executablePath' => 'vendor/bin/phpcbf',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpcbf', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_to_phpmd_command()
    {
        $job = new PhpmdJob(new JobConfiguration('phpmd_src', 'phpmd', [
            'executablePath' => 'vendor/bin/phpmd',
            'paths'          => ['src'],
            'rules'          => 'cleancode',
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpmd', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_to_phpunit_command()
    {
        $job = new PhpunitJob(new JobConfiguration('phpunit', 'phpunit', [
            'executablePath' => 'vendor/bin/phpunit',
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpunit', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_to_psalm_command()
    {
        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', [
            'executablePath' => 'vendor/bin/psalm',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('sail exec app');

        $this->assertStringStartsWith('sail exec app vendor/bin/psalm', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_to_parallel_lint_command()
    {
        $job = new ParallelLintJob(new JobConfiguration('lint', 'parallel-lint', [
            'executablePath' => 'vendor/bin/parallel-lint',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/parallel-lint', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_to_phpcpd_command()
    {
        $job = new PhpcpdJob(new JobConfiguration('cpd', 'phpcpd', [
            'executablePath' => 'vendor/bin/phpcpd',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpcpd', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_to_script_job_command()
    {
        $job = new ScriptJob(new JobConfiguration('my_script', 'script', [
            'executablePath' => 'vendor/bin/my-tool',
            'otherArguments' => '--verbose',
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertEquals('docker exec -i app vendor/bin/my-tool --verbose', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_to_custom_job_structured_mode()
    {
        $job = new CustomJob(new JobConfiguration('eslint_src', 'custom', [
            'executablePath' => 'eslint',
            'paths'          => ['src'],
            'otherArguments' => '--fix',
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertEquals('docker exec -i app eslint src --fix', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_to_custom_job_legacy_mode()
    {
        $job = new CustomJob(new JobConfiguration('lint_js', 'custom', [
            'script' => 'npm run lint -- --fix',
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertEquals('docker exec -i app npm run lint -- --fix', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_to_php_cs_fixer_command()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'executablePath' => 'vendor/bin/php-cs-fixer',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/php-cs-fixer fix', $job->buildCommand());
    }

    /** @test */
    public function executable_prefix_is_prepended_to_rector_command()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executablePath' => 'vendor/bin/rector',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/rector process', $job->buildCommand());
    }

    /** @test */
    public function empty_executable_prefix_does_not_alter_command()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'executablePath' => 'vendor/bin/phpstan',
            'paths'          => ['src'],
        ]));

        $commandBefore = $job->buildCommand();
        $job->applyExecutablePrefix('');

        $this->assertEquals($commandBefore, $job->buildCommand());
    }

    // ========================================================================
    // CLI extra arguments (-- args)
    // ========================================================================

    /** @test */
    public function cli_extra_arguments_are_appended_to_command()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'executablePath' => 'vendor/bin/phpstan',
            'paths'          => ['src'],
        ]));
        $job->applyCliExtraArguments('--memory-limit=2G');

        $command = $job->buildCommand();

        $this->assertStringContainsString('--memory-limit=2G', $command);
        $this->assertStringEndsWith('src', $command);
    }

    /** @test */
    public function cli_extra_arguments_are_placed_after_other_arguments()
    {
        $job = new PhpunitJob(new JobConfiguration('phpunit', 'phpunit', [
            'executablePath'  => 'vendor/bin/phpunit',
            'otherArguments'  => '--colors=always',
        ]));
        $job->applyCliExtraArguments('--filter=testFoo');

        $command = $job->buildCommand();

        $colorsPos = strpos($command, '--colors=always');
        $filterPos = strpos($command, '--filter=testFoo');

        $this->assertNotFalse($colorsPos);
        $this->assertNotFalse($filterPos);
        $this->assertGreaterThan($colorsPos, $filterPos);
    }

    /** @test */
    public function cli_extra_arguments_with_multiple_args()
    {
        $job = new PhpunitJob(new JobConfiguration('phpunit', 'phpunit', [
            'executablePath' => 'vendor/bin/phpunit',
        ]));
        $job->applyCliExtraArguments('--filter=testFoo --testdox');

        $command = $job->buildCommand();

        $this->assertStringContainsString('--filter=testFoo', $command);
        $this->assertStringContainsString('--testdox', $command);
    }

    /** @test */
    public function empty_cli_extra_arguments_do_not_alter_command()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'executablePath' => 'vendor/bin/phpstan',
            'paths'          => ['src'],
        ]));

        $commandBefore = $job->buildCommand();
        $job->applyCliExtraArguments('');

        $this->assertEquals($commandBefore, $job->buildCommand());
    }

    /** @test */
    public function cli_extra_arguments_work_with_custom_job_script_mode()
    {
        $job = new CustomJob(new JobConfiguration('audit', 'custom', [
            'script' => 'composer audit',
        ]));
        $job->applyCliExtraArguments('--format=json');

        $this->assertEquals('composer audit --format=json', $job->buildCommand());
    }

    /** @test */
    public function cli_extra_arguments_work_with_custom_job_structured_mode()
    {
        $job = new CustomJob(new JobConfiguration('eslint', 'custom', [
            'executablePath' => 'eslint',
            'paths'          => ['src'],
            'otherArguments' => '--fix',
        ]));
        $job->applyCliExtraArguments('--max-warnings=0');

        $command = $job->buildCommand();

        // Custom structured: executable paths otherArguments cliExtraArgs
        $this->assertEquals('eslint src --fix --max-warnings=0', $command);
    }

    /** @test */
    public function cli_extra_arguments_combine_with_executable_prefix()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'executablePath' => 'vendor/bin/phpstan',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');
        $job->applyCliExtraArguments('--memory-limit=2G');

        $command = $job->buildCommand();

        $this->assertStringStartsWith('docker exec -i app vendor/bin/phpstan', $command);
        $this->assertStringContainsString('--memory-limit=2G', $command);
    }
}
