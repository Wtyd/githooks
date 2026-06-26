<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * FEAT-20 — `--format=json` for the diagnostic commands, exercised straight from
 * the embedded .phar so the feature is proven to ship: `conf:check`, `status`
 * and `system:info` must each emit a single parseable JSON document on stdout.
 *
 * @group release
 */
class DiagnosticsJsonReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());
    }

    /** @test */
    public function conf_check_emits_json_from_the_phar(): void
    {
        passthru("$this->githooks conf:check --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);

        $this->assertIsArray($decoded, 'stdout must be a single parseable JSON object');
        $this->assertSame(1, $decoded['version']);
        $this->assertTrue($decoded['valid']);
        $this->assertFalse($decoded['legacy']);
        $this->assertArrayHasKey('jobs', $decoded);
    }

    /** @test */
    public function status_emits_json_from_the_phar(): void
    {
        passthru("$this->githooks status --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);

        $this->assertIsArray($decoded, 'stdout must be a single parseable JSON object');
        $this->assertSame(1, $decoded['version']);
        $this->assertArrayHasKey('hooksPath', $decoded);
        $this->assertArrayHasKey('events', $decoded);
    }

    /** @test */
    public function system_info_emits_json_from_the_phar(): void
    {
        passthru("$this->githooks system:info --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);

        $this->assertIsArray($decoded, 'stdout must be a single parseable JSON object');
        $this->assertSame(['version', 'cpus', 'processes', 'warning'], array_keys($decoded));
        $this->assertIsInt($decoded['cpus']);
    }
}
