<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Jobs\PhpCsFixerJob;

class PhpCsFixerJobTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/php-cs-fixer-job-' . uniqid('', true);
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
    public function php_cs_fixer_is_a_supported_job_type()
    {
        $this->assertTrue((new JobRegistry())->isSupported('php-cs-fixer'));
    }

    /** @test */
    public function php_cs_fixer_builds_correct_command_with_all_arguments()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'executable-path' => 'vendor/bin/php-cs-fixer',
            'config'         => '.php-cs-fixer.dist.php',
            'rules'          => '@PSR12',
            'dry-run'        => true,
            'diff'           => true,
            'allow-risky'    => 'yes',
            'using-cache'    => 'no',
            'cache-file'     => '.cache/fixer',
            'paths'          => ['src'],
            'other-arguments' => '--ansi',
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
            'executable-path' => 'vendor/bin/php-cs-fixer',
            'dry-run'        => true,
            'paths'          => ['src'],
        ]));

        $this->assertStringContainsString('--dry-run', $job->buildCommand());
    }

    /** @test */
    public function php_cs_fixer_includes_using_cache_and_cache_file()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'executable-path' => 'vendor/bin/php-cs-fixer',
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
            'executable-path' => 'vendor/bin/php-cs-fixer',
            'paths'          => ['src', 'app'],
        ]));

        $this->assertStringEndsWith('src app', $job->buildCommand());
    }

    /** @test */
    public function php_cs_fixer_ignores_unexpected_arguments()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'executable-path'     => 'vendor/bin/php-cs-fixer',
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
    public function php_cs_fixer_returns_default_cache_paths_when_cache_file_is_absent_and_no_config_present()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths' => ['src'],
        ]));

        $this->assertEquals(['.php-cs-fixer.cache'], $job->getCachePaths());
        $this->assertNull($job->getCacheResolutionWarning());
    }

    /** @test */
    public function php_cs_fixer_falls_back_to_default_when_cache_file_is_whitespace_only()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths'      => ['src'],
            'cache-file' => '   ',
        ]));

        $this->assertSame(['.php-cs-fixer.cache'], $job->getCachePaths());
    }

    /** @test */
    public function php_cs_fixer_trims_whitespace_around_cache_file()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths'      => ['src'],
            'cache-file' => '  qa/.fixer.cache  ',
        ]));

        $this->assertSame(['qa/.fixer.cache'], $job->getCachePaths());
    }

    /** @test */
    public function php_cs_fixer_honours_custom_cache_file_argument_with_absolute_precedence()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths'      => ['src'],
            'cache-file' => '.cache/php-cs-fixer.cache',
        ]));

        $this->assertEquals(['.cache/php-cs-fixer.cache'], $job->getCachePaths());
        $this->assertNull($job->getCacheResolutionWarning());
    }

    /** @test */
    public function php_cs_fixer_reads_setCacheFile_literal_from_config()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/.php-cs-fixer.php', "<?php\nreturn (new PhpCsFixer\\Config())->setCacheFile('/abs/path.cache');\n");

        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame(['/abs/path.cache'], $job->getCachePaths());
        $this->assertNull($job->getCacheResolutionWarning());
    }

    /** @test */
    public function php_cs_fixer_reads_setCacheFile_with_dir_concat()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/.php-cs-fixer.php', "<?php\nreturn (new PhpCsFixer\\Config())->setCacheFile(__DIR__ . '/.fixer.cache');\n");

        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame([dirname(realpath($config)) . '/.fixer.cache'], $job->getCachePaths());
    }

    /** @test */
    public function php_cs_fixer_emits_warning_when_setCacheFile_is_unparseable()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/.php-cs-fixer.php', "<?php\nreturn (new PhpCsFixer\\Config())->setCacheFile(\$cacheFile);\n");

        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame(['.php-cs-fixer.cache'], $job->getCachePaths());
        $warning = $job->getCacheResolutionWarning();
        $this->assertNotNull($warning);
        $this->assertStringContainsString('could not parse setCacheFile', $warning);
        $this->assertStringContainsString('cache-file', $warning);
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
            'executable-path' => 'vendor/bin/php-cs-fixer',
            'paths'          => ['src'],
        ]));
        $job->applyExecutablePrefix('docker exec -i app');

        $this->assertStringStartsWith('docker exec -i app vendor/bin/php-cs-fixer fix', $job->buildCommand());
    }

    /** @test */
    public function php_cs_fixer_with_cli_extra_arguments()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'executable-path' => 'vendor/bin/php-cs-fixer',
            'paths'          => ['src'],
        ]));
        $job->applyCliExtraArguments('--show-progress=dots');

        $command = $job->buildCommand();

        $this->assertStringContainsString('--show-progress=dots', $command);
        $this->assertStringEndsWith('src', $command);
    }

    /**
     * @test
     *
     *     reordered or have one fragment dropped. Only an exact-string match
     *     catches all variants.
     */
    public function php_cs_fixer_warning_message_is_assembled_with_exact_text(): void
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile(
            $sandbox . '/.php-cs-fixer.php',
            "<?php\nreturn (new PhpCsFixer\\Config())->setCacheFile(\$cacheFile);\n"
        );

        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', [
            'paths'  => ['src'],
            'config' => $config,
        ]));
        $job->getCachePaths(); // primes the unresolvable flag

        $expected = "could not parse setCacheFile() in .php-cs-fixer.php (uses a variable or helper); "
            . "declare 'cache-file' on the job to override (php-cs-fixer respects --cache-file over the config)";

        $this->assertSame($expected, $job->getCacheResolutionWarning());
    }

    /**
     * @test
     *
     *     is_string && !=='' && is_file && is_readable)
     *
     * @dataProvider locateConfigGuardScenarios
     */
    public function php_cs_fixer_locate_config_composite_guard(
        $explicitValue,
        bool $expectExplicitUsed,
        string $scenario
    ): void {
        $sandbox = $this->mkSandbox();

        $args = ['paths' => ['src']];
        if ($expectExplicitUsed) {
            $explicit = $this->writeFile(
                $sandbox . '/explicit.php',
                "<?php\nreturn (new PhpCsFixer\\Config())->setCacheFile('/from-explicit.cache');\n"
            );
            $args['config'] = $explicit;
        } else {
            $args['config'] = $explicitValue;
        }

        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', $args));

        if ($expectExplicitUsed) {
            $this->assertSame(['/from-explicit.cache'], $job->getCachePaths(), $scenario);
        } else {
            // Fallback to the default (no cwd candidates exist in tmp sandbox).
            $this->cdInto($sandbox);
            $this->assertSame(['.php-cs-fixer.cache'], $job->getCachePaths(), $scenario);
        }
    }

    /** @return array<string, array{mixed, bool, string}> */
    public function locateConfigGuardScenarios(): array
    {
        return [
            'non-string config arg → guard rejects' => [
                ['nested'], false, 'is_string guard kills LogicalAnd mutants',
            ],
            'empty config arg → guard rejects'       => [
                '',          false, '!=="" guard kills LogicalAnd mutants',
            ],
            'non-existent file → guard rejects'      => [
                '/no/such/.php-cs-fixer.php', false, 'is_file guard kills LogicalAnd mutants',
            ],
            'valid file → guard accepts'             => [
                '__placeholder__', true, 'all four operands accept',
            ],
        ];
    }

    /**
     * @test
     *
     * Two scenarios drive the foreach: (1) ONLY `.php-cs-fixer.dist.php` exists
     * — proves the foreach reaches the second array item (kills
     * ArrayItemRemoval/Foreach_ that would skip the second iteration); (2)
     * BOTH files exist but `.php-cs-fixer.php` is unreadable — proves the
     * is_readable guard rejects it and the foreach continues to the .dist
     * variant.
     *
     * @dataProvider candidateFallbackScenarios
     */
    public function php_cs_fixer_candidate_fallback_chain(
        array $files,
        ?string $unreadable,
        string $expectedFallbackCacheFile,
        string $scenario
    ): void {
        if ($unreadable !== null && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses chmod permission checks');
        }
        $sandbox = $this->mkSandbox();

        foreach ($files as $name => $content) {
            $this->writeFile($sandbox . '/' . $name, $content);
        }
        $unreadablePath = null;
        if ($unreadable !== null) {
            $unreadablePath = $sandbox . '/' . $unreadable;
            chmod($unreadablePath, 0000);
        }

        $this->cdInto($sandbox);

        $job = new PhpCsFixerJob(new JobConfiguration('fixer_src', 'php-cs-fixer', ['paths' => ['src']]));

        try {
            $this->assertSame([$expectedFallbackCacheFile], $job->getCachePaths(), $scenario);
        } finally {
            if ($unreadablePath !== null) {
                chmod($unreadablePath, 0644);
            }
        }
    }

    public function candidateFallbackScenarios(): array
    {
        return [
            'only .php-cs-fixer.dist.php exists — foreach reaches second item' => [
                ['.php-cs-fixer.dist.php' => "<?php\nreturn (new PhpCsFixer\\Config())->setCacheFile('/dist.cache');\n"],
                null,
                '/dist.cache',
                'kills ArrayItemRemoval / Foreach_',
            ],
            'first candidate unreadable, second valid — guard continues' => [
                [
                    '.php-cs-fixer.php'      => "<?php\nreturn (new PhpCsFixer\\Config())->setCacheFile('/main.cache');\n",
                    '.php-cs-fixer.dist.php' => "<?php\nreturn (new PhpCsFixer\\Config())->setCacheFile('/dist.cache');\n",
                ],
                '.php-cs-fixer.php',
                '/dist.cache',
                'kills LogicalAnd on is_file && is_readable',
            ],
        ];
    }

    /** Reusable cwd switch; the existing tearDown restores cwd if changed. */
    private function cdInto(string $dir): void
    {
        if ($this->cwdBefore === null) {
            $this->cwdBefore = getcwd() ?: null;
        }
        chdir($dir);
    }
}
