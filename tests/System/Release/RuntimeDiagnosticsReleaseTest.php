<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * FEAT-14: the runtime diagnostics block (text `--diag` and JSON v2 `runtime`
 * node) must report the real githooks version — the same one `--version` shows,
 * stamped into the `.phar` by the build via `app('git.version')`. The collector
 * used to call `PrettyVersions::getRootPackageVersion()`, which resolves the
 * *consumer's* root package when githooks runs as a distributed dependency and
 * yields `'unknown'` in the bundled binary.
 *
 * Required as @group release because the version source only differs once the
 * code is compiled into the `.phar` and run as a dependency; an asserted
 * regression here means the fix is embedded in the bundled binary.
 *
 * @group release
 */
class RuntimeDiagnosticsReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['noop']]])
            ->setV3Jobs(['noop' => ['type' => 'custom', 'script' => 'true']]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());
    }

    /** @test */
    public function json_runtime_version_is_the_real_version_not_unknown(): void
    {
        passthru("$this->githooks flow qa --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('runtime', $decoded);

        $version = $decoded['runtime']['githooksVersion'] ?? null;
        // Before the fix the bundled binary reported 'unknown' here even though
        // `--version` knew the real version.
        $this->assertNotSame('unknown', $version, 'runtime.githooksVersion is "unknown" in the .phar');
        $this->assertNotSame('', $version);
        $this->assertNotNull($version);
    }

    /** @test */
    public function diag_block_version_is_the_real_version_not_unknown(): void
    {
        passthru("$this->githooks flow qa --diag --config=$this->configPath 2>&1", $exitCode);

        $output = $this->getActualOutput();
        $this->assertSame(0, $exitCode);
        // The compact --diag line starts with "githooks <version> on <platform> …".
        $this->assertStringContainsString('githooks ', $output);
        $this->assertStringNotContainsString('githooks unknown', $output);
    }
}
