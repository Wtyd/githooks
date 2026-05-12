<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\PhpstanJob;

/**
 * Cobertura directa de PhpstanJob::getCachePaths.
 * El detalle de la resolucion (includes, placeholders) vive en
 * PhpstanCacheResolverTest. Aqui solo validamos el cableado job <-> resolver
 * y los caminos de fallback.
 */
class PhpstanJobTest extends TestCase
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

    private function writeNeon(string $name, string $content): string
    {
        $path = $this->sandbox . '/' . $name;
        file_put_contents($path, $content);
        $this->paths[] = $path;
        return $path;
    }
}
