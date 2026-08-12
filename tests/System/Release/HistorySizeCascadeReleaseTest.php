<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for the `history-size` per-key cascade (BUG-34, 3.8): a flow
 * (or declarative meta-flow) declaring its own `options:` block must inherit
 * `flows.options.history-size` instead of silently resetting it to 0. Runs
 * are real (not --dry-run) so the persistence path is exercised end-to-end;
 * the history lands under testsDir/.githooks/history and tearDown wipes it.
 *
 * @group release
 */
class HistorySizeCascadeReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
    }

    /**
     * @param array<string, mixed> $flows
     */
    private function writeConfig(array $flows): void
    {
        $config = [
            'flows' => $flows,
            'jobs' => [
                'noop_job' => [
                    'type' => 'custom',
                    'executable-path' => 'true',
                    'paths' => ['.'],
                ],
            ],
        ];
        file_put_contents($this->configPath, "<?php\nreturn " . var_export($config, true) . ";\n");
    }

    /**
     * @return string[]
     */
    private function historyFiles(): array
    {
        $files = glob(self::TESTS_PATH . '/.githooks/history/*.json');
        return $files === false ? [] : $files;
    }

    /** @test */
    public function phar_cascades_history_size_per_key_when_flow_declares_options(): void
    {
        $this->writeConfig([
            'options' => ['history-size' => 5],
            'qa' => [
                'options' => ['fail-fast' => true],
                'jobs' => ['noop_job'],
            ],
        ]);

        passthru(
            sprintf('cd %s && ./githooks flow qa --format=json --config=githooks.php 2>/dev/null', self::TESTS_PATH),
            $exitCode
        );

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(5, $decoded['effectiveOptions']['historySize']['value'] ?? null);
        $this->assertSame('flows.options', $decoded['effectiveOptions']['historySize']['source'] ?? null);

        $this->assertCount(
            1,
            $this->historyFiles(),
            'Run must persist under .githooks/history with the inherited global history-size'
        );
    }

    /** @test */
    public function phar_cascades_history_size_to_declarative_meta_flow_with_own_options(): void
    {
        $this->writeConfig([
            'options' => ['history-size' => 5],
            'qa' => ['jobs' => ['noop_job']],
            'ci' => [
                'flows' => ['qa'],
                'options' => ['fail-fast' => true],
            ],
        ]);

        passthru(
            sprintf('cd %s && ./githooks flows ci --format=json --config=githooks.php 2>/dev/null', self::TESTS_PATH),
            $exitCode
        );

        $this->assertSame(0, $exitCode);

        $this->assertCount(
            1,
            $this->historyFiles(),
            'Aggregate meta-flow run must persist with the inherited global history-size'
        );
    }
}
