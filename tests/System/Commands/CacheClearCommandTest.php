<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;

class CacheClearCommandTest extends SystemTestCase
{
    private string $configPath;

    /** @var string[] Cache files/dirs created during tests, cleaned up in tearDown */
    private array $cacheArtifacts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['phpcs_src', 'phpmd_src']]])
            ->setV3Jobs([
                'phpcs_src' => ['type' => 'phpcs', 'paths' => [self::TESTS_PATH . '/src'], 'standard' => 'PSR12'],
                'phpmd_src' => ['type' => 'phpmd', 'paths' => [self::TESTS_PATH . '/src'], 'rules' => 'unusedcode'],
            ])
            ->buildInFileSystem();
    }

    protected function tearDown(): void
    {
        foreach ($this->cacheArtifacts as $path) {
            if (is_dir($path)) {
                $this->deleteDirStructure($path);
            } elseif (file_exists($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /** @test */
    public function it_exits_0_when_no_caches_exist()
    {
        $this->artisan("cache:clear --config=$this->configPath")
            ->assertExitCode(0);
    }

    /** @test */
    public function it_clears_cache_for_a_specific_job()
    {
        $this->createCacheFile('.phpcs.cache');

        $this->artisan("cache:clear phpcs_src --config=$this->configPath")
            ->assertExitCode(0);

        $this->assertFileDoesNotExist('.phpcs.cache');
    }

    /** @test */
    public function it_does_not_clear_other_jobs_when_specific_job_given()
    {
        $this->createCacheFile('.phpcs.cache');
        $this->createCacheFile('.phpmd.cache');

        $this->artisan("cache:clear phpcs_src --config=$this->configPath")
            ->assertExitCode(0);

        $this->assertFileDoesNotExist('.phpcs.cache');
        $this->assertFileExists('.phpmd.cache');
    }

    /** @test */
    public function it_clears_caches_for_all_jobs_in_a_flow()
    {
        $this->createCacheFile('.phpcs.cache');
        $this->createCacheFile('.phpmd.cache');

        $this->artisan("cache:clear qa --config=$this->configPath")
            ->assertExitCode(0);

        $this->assertFileDoesNotExist('.phpcs.cache');
        $this->assertFileDoesNotExist('.phpmd.cache');
    }

    /** @test */
    public function it_returns_exit_1_for_unknown_name()
    {
        $this->artisan("cache:clear inventado --config=$this->configPath")
            ->assertExitCode(1);
    }

    /** @test */
    public function it_clears_valid_jobs_and_returns_exit_1_when_mixed_with_unknown()
    {
        $this->createCacheFile('.phpcs.cache');

        $this->artisan("cache:clear phpcs_src inventado --config=$this->configPath")
            ->assertExitCode(1);

        $this->assertFileDoesNotExist('.phpcs.cache');
    }

    /** @test */
    public function it_clears_all_jobs_when_no_arguments_given()
    {
        $this->createCacheFile('.phpcs.cache');
        $this->createCacheFile('.phpmd.cache');

        $this->artisan("cache:clear --config=$this->configPath")
            ->assertExitCode(0);

        $this->assertFileDoesNotExist('.phpcs.cache');
        $this->assertFileDoesNotExist('.phpmd.cache');
    }

    /** @test */
    public function it_rejects_legacy_config()
    {
        $this->configurationFileBuilder = new \Tests\Utils\ConfigurationFileBuilder(self::TESTS_PATH);
        $this->configurationFileBuilder->buildInFileSystem();

        $this->artisan("cache:clear --config=$this->configPath")
            ->assertExitCode(1);
    }

    /** @test */
    public function it_clears_cache_directory()
    {
        $cacheDir = '.psalm/cache';
        $this->createCacheDir($cacheDir, ['results.cache', 'data.bin']);

        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['psalm_src']]])
            ->setV3Jobs([
                'psalm_src' => ['type' => 'psalm', 'paths' => [self::TESTS_PATH . '/src']],
            ])
            ->buildInFileSystem();

        $this->artisan("cache:clear --config=$this->configPath")
            ->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($cacheDir);
    }

    private function createCacheFile(string $path): void
    {
        file_put_contents($path, 'fake');
        $this->cacheArtifacts[] = $path;
    }

    private function createCacheDir(string $dir, array $files = []): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach ($files as $file) {
            file_put_contents("$dir/$file", 'fake');
        }
        $topLevel = explode('/', $dir)[0];
        $this->cacheArtifacts[] = $topLevel;
    }
}
