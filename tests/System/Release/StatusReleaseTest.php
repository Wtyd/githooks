<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class StatusReleaseTest extends ReleaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationFileBuilder->enableV3Mode();

        file_put_contents(
            self::TESTS_PATH . '/githooks.php',
            $this->configurationFileBuilder->buildV3Php()
        );
    }

    /** @test */
    public function it_shows_status_information()
    {
        passthru("$this->githooks status", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('GitHooks Status', $this->getActualOutput());
    }
}
