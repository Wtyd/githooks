<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;
use Tests\Utils\Traits\GitSandboxTrait;

/**
 * `profile` was the only command of the CLI without a single release test:
 * every other one is exercised against the compiled binary by at least one
 * class. A command can be entirely broken in the `.phar` — a class left out of
 * the Box configuration, a renderer that only resolves from the sources — while
 * the whole unit suite stays green, and nothing would catch it before release.
 *
 * The smoke goes through the same route a user does: run a flow a few times so
 * it persists its history under `.githooks/history/`, then read the trend back.
 * That also pins the other half of the 3.8 `history-size` fix — the runs are
 * only there to be profiled if the per-key cascade kept persistence on.
 *
 * Runs inside a throwaway sandbox (GitSandboxTrait) because the history is
 * written relative to the working directory: from the project root these runs
 * would litter the repository's own `.githooks/`. Not tagged `@group git` — the
 * sandbox never touches the project's repository, and that tag would take the
 * class out of the release pipeline, which runs `--group release`.
 *
 * @group release
 */
class ProfileReleaseTest extends ReleaseTestCase
{
    use GitSandboxTrait;

    private string $binary;

    protected function setUp(): void
    {
        parent::setUp();

        // Absolute, because the sandbox changes the working directory.
        $this->binary = (string) realpath($this->githooks);

        $this->setUpGitSandbox();

        $config = <<<'PHP'
<?php
return [
    'flows' => [
        'options' => ['history-size' => 5],
        'qa'      => ['jobs' => ['noop']],
    ],
    'jobs' => ['noop' => ['type' => 'custom', 'script' => 'true']],
];
PHP;
        file_put_contents('githooks.php', $config);
    }

    protected function tearDown(): void
    {
        $this->tearDownGitSandbox();

        parent::tearDown();
    }

    /**
     * `sleep 1` between runs because the record filename carries a `Ymd-His`
     * stamp; same-second runs are a separate case the store's own tests cover.
     */
    private function recordRuns(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            exec("$this->binary flow qa --config=githooks.php 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, 'the flow being profiled must pass: ' . implode("\n", $output));
            if ($i < $times - 1) {
                sleep(1);
            }
        }
    }

    /** @test */
    public function it_charts_the_recorded_runs_of_a_flow(): void
    {
        $this->recordRuns(2);

        exec("$this->binary profile qa 2>&1", $output, $exitCode);
        $rendered = implode("\n", $output);

        $this->assertSame(0, $exitCode, $rendered);
        $this->assertStringContainsString('qa', $rendered);
        $this->assertStringContainsString('runs', $rendered, 'the header states how many runs are charted');
        $this->assertStringContainsString('time', $rendered, 'time is the default metric');
    }

    /** @test */
    public function it_serialises_the_trend_as_json(): void
    {
        $this->recordRuns(2);

        exec("$this->binary profile qa --format=json 2>/dev/null", $output, $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded, 'stdout must be parseable JSON');
        $this->assertSame('qa', $decoded['flow']);
        $this->assertSame('time', $decoded['metric']);
        $this->assertSame('s', $decoded['unit']);
        $this->assertSame(2, $decoded['count'], 'both recorded runs must be charted');
        $this->assertCount(2, $decoded['values']);
        foreach (['min', 'p50', 'p95', 'max', 'sparkline'] as $key) {
            $this->assertArrayHasKey($key, $decoded, "the payload must expose '$key' from the .phar");
        }
    }

    /**
     * @test
     *
     * A flow that was never run has no history: an ordinary answer, not an
     * error, and it must not read like a crash.
     */
    public function a_flow_without_history_is_reported_without_failing(): void
    {
        exec("$this->binary profile never-ran 2>&1", $output, $exitCode);
        $rendered = implode("\n", $output);

        $this->assertStringNotContainsString('Fatal error', $rendered);
        $this->assertStringNotContainsString('Stack trace', $rendered);
        $this->assertStringContainsString("No run history for flow 'never-ran'.", $rendered);
    }
}
