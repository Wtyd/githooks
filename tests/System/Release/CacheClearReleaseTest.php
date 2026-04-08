<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class CacheClearReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';

        $this->configurationFileBuilder->enableV3Mode();

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpcs_src']]])
            ->setV3Jobs([
                'phpcs_src' => ['type' => 'phpcs', 'paths' => [self::TESTS_PATH . '/src'], 'standard' => 'PSR12'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());
    }

    /** @test */
    public function it_clears_caches_reporting_not_found()
    {
        @unlink('.phpcs.cache');

        passthru("$this->githooks cache:clear --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('not found', $this->getActualOutput());
    }

    /** @test */
    public function it_clears_specific_job_cache()
    {
        file_put_contents('.phpcs.cache', 'fake cache');

        passthru("$this->githooks cache:clear phpcs_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('deleted', $this->getActualOutput());
        $this->assertFileDoesNotExist('.phpcs.cache');
    }
}
