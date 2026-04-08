<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class MigrateConfigurationReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configPath = self::TESTS_PATH . '/githooks.php';
    }

    /** @test */
    public function it_migrates_v2_config_successfully()
    {
        file_put_contents(
            $this->configPath,
            $this->configurationFileBuilder->buildPhp()
        );

        passthru("$this->githooks conf:migrate --config=$this->configPath", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Migrated to v3', $this->getActualOutput());
        $this->assertFileExists($this->configPath . '.v2.bak');
    }

    /** @test */
    public function it_detects_v3_format()
    {
        $this->configurationFileBuilder->enableV3Mode();

        file_put_contents(
            $this->configPath,
            $this->configurationFileBuilder->buildV3Php()
        );

        passthru("$this->githooks conf:migrate --config=$this->configPath", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('already in v3', $this->getActualOutput());
    }
}
