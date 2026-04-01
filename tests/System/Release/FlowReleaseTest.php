<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class FlowReleaseTest extends ReleaseTestCase
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
    public function it_executes_flow_with_all_jobs_passing()
    {
        passthru("$this->githooks flow qa", $exitCode);

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_exits_with_error_for_undefined_flow()
    {
        passthru("$this->githooks flow nonexistent 2>&1", $exitCode);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('is not defined', $this->getActualOutput());
    }

    /** @test */
    public function it_excludes_jobs_via_cli()
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['pass_job', 'fail_job']]])
            ->setV3Jobs([
                'pass_job' => ['type' => 'custom', 'script' => 'echo pass'],
                'fail_job' => ['type' => 'custom', 'script' => 'echo fail && exit 1'],
            ]);

        file_put_contents(
            self::TESTS_PATH . '/githooks.php',
            $this->configurationFileBuilder->buildV3Php()
        );

        passthru("$this->githooks flow qa --exclude-jobs=fail_job", $exitCode);

        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function it_outputs_json_format()
    {
        passthru("$this->githooks flow qa --format=json", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $decoded = json_decode($output, true);
        $this->assertNotNull($decoded, 'Output is not valid JSON: ' . $output);
        $this->assertEquals('qa', $decoded['flow']);
    }
}
