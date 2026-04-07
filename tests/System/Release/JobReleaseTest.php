<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class JobReleaseTest extends ReleaseTestCase
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
    public function it_executes_single_job()
    {
        passthru("$this->githooks job phpcs_src", $exitCode);

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_applies_fast_mode_to_single_job()
    {
        $this->configurationFileBuilder
            ->setV3Jobs([
                'lint_job' => ['type' => 'custom', 'executablePath' => '/bin/true', 'paths' => ['src'], 'accelerable' => true],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['lint_job']]]);

        file_put_contents(
            self::TESTS_PATH . '/githooks.php',
            $this->configurationFileBuilder->buildV3Php()
        );

        passthru("$this->githooks job lint_job --fast --dry-run 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_exits_with_error_for_undefined_job()
    {
        passthru("$this->githooks job nonexistent 2>&1", $exitCode);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('is not defined', $this->getActualOutput());
    }
}
