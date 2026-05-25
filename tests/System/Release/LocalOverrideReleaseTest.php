<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for `githooks.local.php` per-developer override introduced
 * in 3.1. Merges over the main config with `array_replace_recursive`.
 *
 * @group release
 */
class LocalOverrideReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    private string $localConfigPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->localConfigPath = self::TESTS_PATH . '/githooks.local.php';

        $this->configurationFileBuilder->enableV3Mode();
    }

    protected function tearDown(): void
    {
        @unlink($this->localConfigPath);
        parent::tearDown();
    }

    /** @test */
    public function local_override_merges_executable_prefix(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['my_job']]])
            ->setV3Jobs([
                'my_job' => [
                    'type' => 'custom',
                    'executablePath' => 'original-tool',
                    'paths' => ['src'],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        file_put_contents($this->localConfigPath, '<?php return [
            "flows" => ["options" => ["executable-prefix" => "/bin/echo DOCKER"]],
        ];');

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('DOCKER original-tool', $this->getActualOutput());
    }

    /** @test */
    public function local_override_replaces_job_property(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['my_job']]])
            ->setV3Jobs([
                'my_job' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                    'otherArguments' => '--original',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        file_put_contents($this->localConfigPath, '<?php return [
            "jobs" => [
                "my_job" => ["otherArguments" => "--overridden"],
            ],
        ];');

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('--overridden', $output);
    }

    /** @test */
    public function without_local_override_works_normally(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['my_job']]])
            ->setV3Jobs([
                'my_job' => ['type' => 'custom', 'script' => '/bin/echo no-override'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('/bin/echo no-override', $this->getActualOutput());
    }

    /** @test */
    public function local_override_and_prefix_work_together(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['my_job']]])
            ->setV3Jobs([
                'my_job' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        file_put_contents($this->localConfigPath, '<?php return [
            "flows" => ["options" => ["executable-prefix" => "/bin/echo DOCKER"]],
        ];');

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();

        $this->assertStringContainsString('DOCKER', $output);
        $this->assertStringContainsString('/bin/echo', $output);
        $this->assertStringContainsString('src', $output);
    }
}
