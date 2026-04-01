<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class MigrateConfigurationReleaseTest extends ReleaseTestCase
{
    /** @test */
    public function it_migrates_v2_config_successfully()
    {
        // Write legacy config
        file_put_contents(
            self::TESTS_PATH . '/githooks.php',
            $this->configurationFileBuilder->buildPhp()
        );

        passthru("$this->githooks conf:migrate", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Migrated to v3', $this->getActualOutput());
        $this->assertFileExists(self::TESTS_PATH . '/githooks.php.v2.bak');
    }

    /** @test */
    public function it_detects_v3_format()
    {
        $this->configurationFileBuilder->enableV3Mode();

        file_put_contents(
            self::TESTS_PATH . '/githooks.php',
            $this->configurationFileBuilder->buildV3Php()
        );

        passthru("$this->githooks conf:migrate", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('already in v3', $this->getActualOutput());
    }
}
