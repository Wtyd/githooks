<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for v3.3 item 3: --files / --files-from / --exclude-pattern.
 * Verifies that the .phar binary exposes the new flags and that the JSON v2
 * contract is satisfied end-to-end.
 *
 * @group release
 */
class V33FilesFlagReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();

        $this->configurationFileBuilder
            ->setV3Hooks([])
            ->setV3Flows(['qa' => ['jobs' => ['lint_src']]])
            ->setV3Jobs([
                'lint_src' => [
                    'type'        => 'custom',
                    'script'      => '/bin/true',
                    'paths'       => [self::TESTS_PATH . '/src'],
                    'accelerable' => true,
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());
        file_put_contents(self::TESTS_PATH . '/src/User.php', '<?php');
    }

    /** @test */
    public function phar_runs_flow_with_files_and_emits_input_files_block(): void
    {
        $cmd = sprintf(
            '%s flow qa --files=%s/src/User.php --format=json --config=%s 2>/dev/null',
            $this->githooks,
            self::TESTS_PATH,
            $this->configPath
        );

        passthru($cmd, $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);

        $this->assertIsArray($decoded);
        $this->assertSame('files', $decoded['executionMode']);
        $this->assertArrayHasKey('inputFiles', $decoded);
        $this->assertSame('cli', $decoded['inputFiles']['source']);
        $this->assertSame(1, $decoded['inputFiles']['totalValid']);
    }

    /** @test */
    public function phar_rejects_mutually_exclusive_files_flags(): void
    {
        $manifest = self::TESTS_PATH . '/list.txt';
        file_put_contents($manifest, self::TESTS_PATH . "/src/User.php\n");

        $cmd = sprintf(
            '%s flow qa --files=%s/src/User.php --files-from=%s --config=%s 2>&1',
            $this->githooks,
            self::TESTS_PATH,
            $manifest,
            $this->configPath
        );

        passthru($cmd, $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('mutually exclusive', $this->getActualOutput());
    }

    /** @test */
    public function phar_rejects_exclude_pattern_without_input(): void
    {
        $cmd = sprintf(
            '%s flow qa --exclude-pattern=**/*Test.php --config=%s 2>&1',
            $this->githooks,
            $this->configPath
        );

        passthru($cmd, $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--exclude-pattern requires --files or --files-from', $this->getActualOutput());
    }
}
