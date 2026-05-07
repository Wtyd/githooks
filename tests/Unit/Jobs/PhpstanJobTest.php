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

    private function writeNeon(string $name, string $content): string
    {
        $path = $this->sandbox . '/' . $name;
        file_put_contents($path, $content);
        $this->paths[] = $path;
        return $path;
    }
}
