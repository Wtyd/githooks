<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\RectorJob;

class RectorJobTest extends TestCase
{
    /** @var string[] */
    private array $sandboxPaths = [];

    /** @var string[] */
    private array $sandboxDirs = [];

    private ?string $cwdBefore = null;

    protected function tearDown(): void
    {
        if ($this->cwdBefore !== null) {
            chdir($this->cwdBefore);
            $this->cwdBefore = null;
        }
        foreach ($this->sandboxPaths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
        foreach ($this->sandboxDirs as $d) {
            if (is_dir($d)) {
                @rmdir($d);
            }
        }
        parent::tearDown();
    }

    private function mkSandbox(): string
    {
        $dir = sys_get_temp_dir() . '/rector-job-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->sandboxDirs[] = $dir;
        return $dir;
    }

    private function writeFile(string $path, string $content): string
    {
        file_put_contents($path, $content);
        $this->sandboxPaths[] = $path;
        return $path;
    }

    /** @test */
    public function rector_is_a_supported_job_type()
    {
        $this->assertTrue((new JobRegistry())->isSupported('rector'));
    }

    /** @test */
    public function rector_builds_correct_command_with_all_arguments()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executable-path'  => 'vendor/bin/rector',
            'config'          => 'rector.php',
            'dry-run'         => true,
            'clear-cache'     => true,
            'no-progress-bar' => true,
            'paths'           => ['src'],
            'other-arguments'  => '--ansi',
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString('vendor/bin/rector process', $command);
        $this->assertStringContainsString('--config=rector.php', $command);
        $this->assertStringContainsString('--dry-run', $command);
        $this->assertStringContainsString('--clear-cache', $command);
        $this->assertStringContainsString('--no-progress-bar', $command);
        $this->assertStringContainsString('--ansi', $command);
        $this->assertStringEndsWith('src', $command);
    }

    /** @test */
    public function rector_uses_default_executable_when_executable_path_is_empty()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths' => ['src'],
        ]));

        $command = $job->buildCommand();

        $this->assertMatchesRegularExpression('/^(vendor\/bin\/)?rector process/', $command);
    }

    /** @test */
    public function rector_includes_dry_run_and_clear_cache_flags()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executable-path' => 'vendor/bin/rector',
            'dry-run'        => true,
            'clear-cache'    => true,
            'paths'          => ['src'],
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString('--dry-run', $command);
        $this->assertStringContainsString('--clear-cache', $command);
    }

    /** @test */
    public function rector_runs_against_several_paths()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executable-path' => 'vendor/bin/rector',
            'paths'          => ['src', 'app'],
        ]));

        $this->assertStringEndsWith('src app', $job->buildCommand());
    }

    /** @test */
    public function rector_ignores_unexpected_arguments()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executable-path'     => 'vendor/bin/rector',
            'paths'              => ['src'],
            'unexpected_arg'     => 'value',
            'another_bad_key'    => true,
        ]));

        $command = $job->buildCommand();

        $this->assertStringNotContainsString('unexpected_arg', $command);
        $this->assertStringNotContainsString('another_bad_key', $command);
    }

    /** @test */
    public function rector_detects_fix_applied_on_success()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths' => ['src'],
        ]));

        $this->assertTrue($job->isFixApplied(0));
    }

    /** @test */
    public function rector_does_not_detect_fix_in_dry_run_mode()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'dry-run' => true,
            'paths'   => ['src'],
        ]));

        $this->assertFalse($job->isFixApplied(0));
    }

    /** @test */
    public function rector_does_not_detect_fix_on_error_exit_code()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths' => ['src'],
        ]));

        $this->assertFalse($job->isFixApplied(1));
        $this->assertFalse($job->isFixApplied(2));
    }

    /** @test */
    public function rector_default_cache_path_is_system_tmp_when_no_config_file_present()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths' => ['src'],
        ]));

        $this->assertEquals([sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rector_cached_files'], $job->getCachePaths());
        $this->assertNull($job->getCacheResolutionWarning());
    }

    /** @test */
    public function rector_meta_arg_cache_dir_takes_absolute_precedence()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths'     => ['src'],
            'cache-dir' => 'qa/.rector-cache',
        ]));

        $this->assertSame(['qa/.rector-cache'], $job->getCachePaths());
        $this->assertNull($job->getCacheResolutionWarning());
    }

    /** @test */
    public function rector_reads_cache_directory_literal_from_rector_php()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/rector.php', "<?php\nreturn function (\$c) { \$c->cacheDirectory('/abs/cache'); };\n");

        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame(['/abs/cache'], $job->getCachePaths());
        $this->assertNull($job->getCacheResolutionWarning());
    }

    /** @test */
    public function rector_reads_cache_directory_with_dir_concat()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/rector.php', "<?php\nreturn function (\$c) { \$c->cacheDirectory(__DIR__ . '/.rector-cache'); };\n");

        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame([dirname(realpath($config)) . '/.rector-cache'], $job->getCachePaths());
    }

    /** @test */
    public function rector_falls_back_to_default_when_meta_arg_cache_dir_is_empty_string()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths'     => ['src'],
            'cache-dir' => '',
        ]));

        $this->assertSame([sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rector_cached_files'], $job->getCachePaths());
    }

    /** @test */
    public function rector_falls_back_to_default_when_meta_arg_cache_dir_is_not_a_string()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths'     => ['src'],
            'cache-dir' => true,
        ]));

        $this->assertSame([sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rector_cached_files'], $job->getCachePaths());
    }

    /** @test */
    public function rector_falls_back_to_default_when_meta_arg_cache_dir_is_whitespace_only()
    {
        // Adversarial: '   ' is not a real path; must not be returned verbatim.
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths'     => ['src'],
            'cache-dir' => '   ',
        ]));

        $this->assertSame([sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rector_cached_files'], $job->getCachePaths());
    }

    /** @test */
    public function rector_trims_whitespace_around_meta_arg_cache_dir()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths'     => ['src'],
            'cache-dir' => '  qa/.rector-cache  ',
        ]));

        $this->assertSame(['qa/.rector-cache'], $job->getCachePaths());
    }

    /** @test */
    public function rector_resolution_warning_resets_between_calls()
    {
        // Flag must be set by getCachePaths and not leak from a previous call
        // when the next call has no config to evaluate.
        $sandbox = $this->mkSandbox();
        $bad = $this->writeFile($sandbox . '/rector.php', "<?php\nreturn function (\$c) { \$c->cacheDirectory(\$x); };\n");

        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths'  => ['src'],
            'config' => $bad,
        ]));
        $job->getCachePaths();
        $this->assertNotNull($job->getCacheResolutionWarning());

        // Reuse the instance with no config (simulating an earlier-loaded job).
        $reflection = new \ReflectionClass($job);
        $args = $reflection->getProperty('args');
        $args->setAccessible(true);
        $args->setValue($job, ['paths' => ['src']]);
        $job->getCachePaths();

        $this->assertNull($job->getCacheResolutionWarning());
    }

    /** @test */
    public function rector_emits_warning_when_cache_directory_is_unparseable()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/rector.php', "<?php\nreturn function (\$c) { \$c->cacheDirectory(\$cacheDir); };\n");

        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame([sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rector_cached_files'], $job->getCachePaths());
        $warning = $job->getCacheResolutionWarning();
        $this->assertNotNull($warning);
        $this->assertStringContainsString('could not parse cacheDirectory', $warning);
    }

    /** @test */
    public function rector_has_no_thread_capability()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'paths' => ['src'],
        ]));

        $this->assertNull($job->getThreadCapability());
    }

    /** @test */
    public function rector_is_accelerable()
    {
        $this->assertTrue((new JobRegistry())->isAccelerable('rector'));
    }

    /** @test */
    public function rector_with_executable_prefix()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executable-path' => 'vendor/bin/rector',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/rector process', $job->buildCommand());
    }

    /** @test */
    public function rector_with_cli_extra_arguments()
    {
        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', [
            'executable-path' => 'vendor/bin/rector',
            'paths'          => ['src'],
        ]));
        $job->applyCliExtraArguments('--debug');

        $command = $job->buildCommand();

        $this->assertStringContainsString('--debug', $command);
        $this->assertStringEndsWith('src', $command);
    }

    /**
     * @test
     *
     *     is_string($explicit) && $explicit !== '' && is_file($explicit) && is_readable($explicit))
     *
     * @dataProvider explicitConfigGuardScenarios
     */
    public function rector_locate_config_explicit_guard(
        $explicitValue,
        bool $expectUsed,
        string $scenario
    ): void {
        $sandbox = $this->mkSandbox();

        $args = ['paths' => ['src']];
        if ($expectUsed) {
            $explicit = $this->writeFile(
                $sandbox . '/explicit-rector.php',
                "<?php\nreturn (new \\Rector\\Config\\RectorConfig())->cacheDirectory('/from-explicit.cache');\n"
            );
            $args['config'] = $explicit;
        } else {
            $args['config'] = $explicitValue;
        }

        $job = new RectorJob(new JobConfiguration('rector_src', 'rector', $args));

        if ($expectUsed) {
            $this->assertSame(['/from-explicit.cache'], $job->getCachePaths(), $scenario);
        } else {
            $this->cdInto($sandbox); // ensures no `rector.php` in cwd
            $this->assertSame(
                [sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rector_cached_files'],
                $job->getCachePaths(),
                $scenario
            );
        }
    }

    /** @return array<string, array{mixed, bool, string}> */
    public function explicitConfigGuardScenarios(): array
    {
        return [
            'non-string explicit → guard rejects' => [
                ['nested'], false, 'is_string short-circuit',
            ],
            'empty explicit → guard rejects' => [
                '', false, '!=="" short-circuit',
            ],
            'non-existent explicit → guard rejects' => [
                '/no/such/rector.php', false, 'is_file short-circuit',
            ],
            'valid explicit → guard accepts' => [
                '__placeholder__', true, 'all four operands accept',
            ],
        ];
    }

    /**
     * @test
     *
     * Two adjacent assertions: rector.php present-and-readable in cwd ⇒ it
     * is picked up; rector.php present but chmod 0000 ⇒ falls back. With the
     * `&&`→`||` mutant, the second case would still return 'rector.php' and
     * the cache path would not match the default.
     */
    public function rector_locate_config_cwd_fallback_pair(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses chmod permission checks');
        }

        // Case A: rector.php in cwd is readable → used.
        $sandboxOk = $this->mkSandbox();
        $okConfig = $this->writeFile(
            $sandboxOk . '/rector.php',
            "<?php\nreturn (new \\Rector\\Config\\RectorConfig())->cacheDirectory('/from-cwd.cache');\n"
        );
        $this->cdInto($sandboxOk);

        $jobOk = new RectorJob(new JobConfiguration('rector_src', 'rector', ['paths' => ['src']]));
        $this->assertSame(['/from-cwd.cache'], $jobOk->getCachePaths(), 'cwd rector.php must be picked when readable');

        // Restore cwd before the unreadable scenario so the sandboxes don't interfere.
        if ($this->cwdBefore !== null) {
            chdir($this->cwdBefore);
            $this->cwdBefore = null;
        }

        // Case B: rector.php exists but is unreadable → falls back to default.
        $sandboxKo = $this->mkSandbox();
        $koConfig = $this->writeFile(
            $sandboxKo . '/rector.php',
            "<?php\nreturn (new \\Rector\\Config\\RectorConfig())->cacheDirectory('/must-not-be-used.cache');\n"
        );
        chmod($koConfig, 0000);
        $this->cdInto($sandboxKo);

        $jobKo = new RectorJob(new JobConfiguration('rector_src', 'rector', ['paths' => ['src']]));

        try {
            $this->assertSame(
                [sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rector_cached_files'],
                $jobKo->getCachePaths(),
                'unreadable cwd rector.php must NOT be picked'
            );
        } finally {
            chmod($koConfig, 0644);
        }
    }

    private function cdInto(string $dir): void
    {
        if ($this->cwdBefore === null) {
            $this->cwdBefore = getcwd() ?: null;
        }
        chdir($dir);
    }
}
