<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\PhpstanJob;

/**
 * Cobertura directa de PhpstanJob::getCachePaths.
 * El detalle de la resolucion (includes, placeholders) vive en
 * PhpstanCacheResolverTest. Aqui solo validamos el cableado job <-> resolver
 * y los caminos de fallback.
 */
class PhpstanJobTest extends UnitTestCase
{
    /** @var string[] */
    private array $paths = [];

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/phpstan-job-' . uniqid('', true);
        mkdir($this->sandbox, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
        if (is_dir($this->sandbox)) {
            @rmdir($this->sandbox);
        }
        parent::tearDown();
    }

    /** @test */
    public function default_cache_path_uses_system_tmp_when_config_arg_is_absent()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]));

        $this->assertSame([sys_get_temp_dir() . '/phpstan'], $job->getCachePaths());
    }

    /** @test */
    public function default_cache_path_used_when_config_file_does_not_exist()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths'  => ['src'],
            'config' => $this->sandbox . '/missing.neon',
        ]));

        $this->assertSame([sys_get_temp_dir() . '/phpstan'], $job->getCachePaths());
    }

    /** @test */
    public function default_cache_path_used_when_neon_has_no_tmpdir()
    {
        $config = $this->writeNeon('plain.neon', "parameters:\n    level: 8\n");

        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame([sys_get_temp_dir() . '/phpstan'], $job->getCachePaths());
    }

    /** @test */
    public function reads_tmpdir_from_neon_with_placeholder()
    {
        $config = $this->writeNeon(
            'cwd.neon',
            "parameters:\n    tmpDir: %currentWorkingDirectory%/qa/luz/cache\n"
        );

        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $expected = (getcwd() ?: '.') . '/qa/luz/cache';
        $this->assertSame([$expected], $job->getCachePaths());
    }

    /** @test */
    public function emits_warning_and_falls_back_when_tmpdir_contains_unexpanded_placeholder()
    {
        // Adversarial: tmpDir uses %env.HOME% which we don't expand. The
        // resolved value is literal, useless on disk. The job recognises
        // the unexpanded '%' and surfaces a warning.
        $config = $this->writeNeon('env.neon', "parameters:\n    tmpDir: %env.HOME%/cache\n");

        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame([sys_get_temp_dir() . '/phpstan'], $job->getCachePaths());
        $warning = $job->getCacheResolutionWarning();
        $this->assertNotNull($warning);
        $this->assertStringContainsString('placeholder', $warning);
    }

    /** @test */
    public function ignores_tmpdir_inside_services_block()
    {
        // Adversarial: a service constructor argument named tmpDir would be
        // a false positive. The resolver only picks tmpDir under top-level
        // parameters: — the job stays on the default.
        $config = $this->writeNeon(
            'services.neon',
            "services:\n    -\n        class: My\\Service\n        arguments:\n            tmpDir: '/wrong'\nparameters:\n    level: 8\n"
        );

        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame([sys_get_temp_dir() . '/phpstan'], $job->getCachePaths());
        $this->assertNull($job->getCacheResolutionWarning());
    }

    /** @test */
    public function placeholder_warning_resets_between_calls()
    {
        $bad = $this->writeNeon('bad.neon', "parameters:\n    tmpDir: %env.X%/cache\n");
        $good = $this->writeNeon('good.neon', "parameters:\n    tmpDir: literal/cache\n");

        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths'  => ['src'],
            'config' => $bad,
        ]));
        $job->getCachePaths();
        $this->assertNotNull($job->getCacheResolutionWarning());

        // Reuse the instance with a clean config (simulating subsequent calls).
        $reflection = new \ReflectionClass($job);
        $args = $reflection->getProperty('args');
        $args->setAccessible(true);
        $args->setValue($job, ['paths' => ['src'], 'config' => $good]);
        $job->getCachePaths();

        $this->assertNull($job->getCacheResolutionWarning());
    }

    /** @test */
    public function follows_includes_chain_to_find_tmpdir_in_base()
    {
        $this->writeNeon('base.neon', "parameters:\n    tmpDir: %rootDir%/cache\n");
        $config = $this->writeNeon('ci.neon', "includes:\n    - base.neon\nparameters:\n    level: 8\n");

        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame([$this->sandbox . '/cache'], $job->getCachePaths());
    }

    /**
     * BUG-1: PHPStan emits `[ERROR] No files found to analyse.` (stderr) and
     * exit code 1 when `excludePaths.analyse` of the active config strips every
     * input file. The wrapper concatenates stderr after stdout before consulting
     * isEmptyInputTolerated(), so the heuristic operates on the combined string.
     *
     * @dataProvider emptyInputToleranceProvider
     */
    public function test_empty_input_tolerance_matches_phpstan_exit_signature(
        int $exitCode,
        string $output,
        bool $expected,
        string $scenario
    ): void {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]));

        $this->assertSame(
            $expected,
            $job->isEmptyInputTolerated($exitCode, $output),
            "Scenario: $scenario"
        );
    }

    /** @return array<string, array{int, string, bool, string}> */
    public function emptyInputToleranceProvider(): array
    {
        $marker = 'No files found to analyse';

        return [
            'exit=1 + exact marker (stderr concatenated to output)' => [
                1,
                " 0/0 [>] 100%\n\n [ERROR] $marker\n",
                true,
                'real PHPStan output when all inputs match excludePaths.analyse',
            ],
            'exit=1 + marker as substring of longer text' => [
                1,
                "Some prefix... $marker. Suffix.",
                true,
                'str_contains tolerates surrounding context',
            ],
            'exit=1 + real violations without marker' => [
                1,
                " ------ -------\n  Line   src/Foo.php\n  42     Class Foo not found.\n",
                false,
                'real failure must NOT be reinterpreted as skipped',
            ],
            'exit=1 + empty output' => [
                1,
                '',
                false,
                'no marker present',
            ],
            'exit=0 + marker present (defensive)' => [
                0,
                "[ERROR] $marker",
                false,
                'success exit code, never reinterpret',
            ],
            'exit=2 + marker present (defensive)' => [
                2,
                "[ERROR] $marker",
                false,
                'only exit=1 is the empty-input signature',
            ],
            'exit=1 + marker case mismatch' => [
                1,
                '[ERROR] no files found to analyse',
                false,
                'matcher is intentionally case-sensitive — phpstan emits this string verbatim',
            ],
            'exit=1 + marker on its own line' => [
                1,
                "$marker\n",
                true,
                'minimal valid output',
            ],
        ];
    }

    /**
     * @test
     *
     * The structured-output adapter mutates internal args (introspectable
     * via Reflection) and signals success with a literal `true`. Both
     * facets are observable contracts.
     */
    public function apply_structured_output_format_mutates_args_and_signals_success(): void
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths' => ['src'],
        ]));

        $result = $job->applyStructuredOutputFormat();
        $this->assertTrue($result, 'applyStructuredOutputFormat must return true (signalling support)');

        $ref = new \ReflectionClass($job);
        $argsProp = $ref->getProperty('args');
        $argsProp->setAccessible(true);
        /** @var array<string, mixed> $args */
        $args = $argsProp->getValue($job);

        $this->assertSame('json', $args['error-format'] ?? null, 'error-format must be json');
        $this->assertTrue($args['no-progress'] ?? null, 'no-progress must be literal true (kills TrueValue mutant)');
    }

    /**
     * @test
     *
     * `getCacheResolutionWarning` builds the warning by concatenating three
     * literal fragments. The mutants either shuffle or drop a fragment; the
     * only way to catch all four is to assert the FULL message verbatim.
     */
    public function cache_resolution_warning_is_assembled_with_the_exact_text(): void
    {
        $config = $this->writeNeon('unexpand.neon', "parameters:\n    tmpDir: %env.HOME%/cache\n");

        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths'  => ['src'],
            'config' => $config,
        ]));
        $job->getCachePaths(); // primes $cacheUnresolvable

        $expected = 'tmpDir in the .neon contains a placeholder that GitHooks does not expand '
            . '(only %currentWorkingDirectory% and %rootDir% are recognised); '
            . 'the cache lives elsewhere — clear it manually';

        $this->assertSame($expected, $job->getCacheResolutionWarning());
    }

    /**
     * @test
     *
     *     `config` arg is absent OR file does not exist)
     *
     * Two scenarios anchor the fallback path: no `config` arg supplied,
     * and `config` arg pointing to a non-existent file. Both must yield
     * the canonical worker count = 4 (matches PHPStan's hard-coded
     * default when `maximumNumberOfProcesses` is not declared).
     *
     * @dataProvider missingConfigScenarios
     */
    public function declared_neon_workers_falls_back_to_4_when_config_is_missing(
        array $args,
        string $scenario
    ): void {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', $args));
        $this->assertSame(4, $job->getDeclaredNeonWorkers(), $scenario);
    }

    public function missingConfigScenarios(): array
    {
        return [
            'no config arg at all' => [
                ['paths' => ['src']],
                'empty($config) branch',
            ],
            'config arg pointing to non-existent file' => [
                ['paths' => ['src'], 'config' => '/no/such/phpstan.neon'],
                '!file_exists($config) branch',
            ],
        ];
    }

    /**
     * @test
     *
     * NEON exists, has parameters, but no `maximumNumberOfProcesses` key.
     * The implementation must return the canonical 4 (matches PHPStan's
     * own default) — any mutated integer value would be observable here.
     */
    public function declared_neon_workers_returns_4_when_neon_omits_max_processes(): void
    {
        $config = $this->writeNeon('no-mnp.neon', "parameters:\n    level: 8\n    paths:\n        - src\n");

        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame(4, $job->getDeclaredNeonWorkers());
    }

    /**
     * Companion assertion to the above: when the NEON DOES declare
     * `maximumNumberOfProcesses: N`, that exact integer is returned. This
     * doesn't kill an existing mutant on its own, but it cements the
     * contract — without it the test above would also pass if the
     * implementation always returned 4 unconditionally.
     *
     * @test
     */
    public function declared_neon_workers_reads_the_neon_value_when_present(): void
    {
        $config = $this->writeNeon('mnp.neon', "parameters:\n    maximumNumberOfProcesses: 7\n    paths:\n        - src\n");

        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame(7, $job->getDeclaredNeonWorkers());
    }

    private function writeNeon(string $name, string $content): string
    {
        $path = $this->sandbox . '/' . $name;
        file_put_contents($path, $content);
        $this->paths[] = $path;
        return $path;
    }
}
