<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\History\RunHistoryStore;
use Wtyd\GitHooks\Utils\Storage;

/**
 * FEAT-5 · persistence activation factor table (--save-history × history-size ×
 * dry-run) exercised end-to-end through `flow`. Asserts whether a run lands
 * under .githooks/history/.
 */
class HistoryPersistenceTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
    }

    /**
     * @param array<string, mixed> $globalOptions
     */
    private function buildConfig(array $globalOptions = []): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['ok']]])
            ->setV3Jobs(['ok' => ['type' => 'custom', 'script' => 'true']]);

        if ($globalOptions !== []) {
            $this->configurationFileBuilder->setV3GlobalOptions($globalOptions);
        }

        $this->configurationFileBuilder->buildInFileSystem();
    }

    private function historyCount(): int
    {
        return count(Storage::files(RunHistoryStore::HISTORY_DIR));
    }

    /** @test */
    public function no_flag_and_no_config_does_not_persist(): void
    {
        $this->buildConfig();

        $this->artisan("flow qa --config=$this->configPath")->assertExitCode(0);

        $this->assertSame(0, $this->historyCount());
    }

    /** @test */
    public function history_size_in_config_persists_without_the_flag(): void
    {
        $this->buildConfig(['history-size' => 30]);

        $this->artisan("flow qa --config=$this->configPath")->assertExitCode(0);

        $this->assertSame(1, $this->historyCount());
    }

    /** @test */
    public function save_history_flag_persists_without_config(): void
    {
        $this->buildConfig();

        $this->artisan("flow qa --save-history --config=$this->configPath")->assertExitCode(0);

        $this->assertSame(1, $this->historyCount());
    }

    /**
     * Per-command variant of `save_history_flag_persists_without_config`:
     * the `flows` runner resolves the default size in its own code path
     * (FlowsRunner:170) — the escaped GreaterThan mutant there broke
     * `--save-history` with no configured history-size ONLY for `flows`.
     *
     * @test
     */
    public function save_history_flag_persists_without_config_in_flows_command(): void
    {
        $this->buildConfig();

        $this->artisan("flows qa --save-history --config=$this->configPath")->assertExitCode(0);

        $this->assertSame(1, $this->historyCount());
    }

    /** @test */
    public function dry_run_never_persists_even_with_flag(): void
    {
        $this->buildConfig(['history-size' => 30]);

        $this->artisan("flow qa --save-history --dry-run --config=$this->configPath")->assertExitCode(0);

        $this->assertSame(0, $this->historyCount());
    }

    /** @test */
    public function persisted_run_is_readable_by_profile_list(): void
    {
        $this->buildConfig();

        $this->artisan("flow qa --save-history --config=$this->configPath")->assertExitCode(0);
        $this->artisan('profile:list qa')
            ->containsStringInOutput('Passed')
            ->assertExitCode(0);
    }

    /**
     * BUG-34: a flow declaring its own options block (for an unrelated key)
     * must still inherit the global history-size per-key.
     *
     * @test
     */
    public function history_size_cascades_to_flow_with_own_options_block(): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['options' => ['processes' => 2], 'jobs' => ['ok']]])
            ->setV3Jobs(['ok' => ['type' => 'custom', 'script' => 'true']])
            ->setV3GlobalOptions(['history-size' => 30]);
        $this->configurationFileBuilder->buildInFileSystem();

        $this->artisan("flow qa --config=$this->configPath")->assertExitCode(0);

        $this->assertSame(1, $this->historyCount());
    }

    /**
     * BUG-34 as reported in production: declarative meta-flow with its own
     * options block — the aggregate run must persist with the global size.
     *
     * @test
     */
    public function history_size_cascades_to_declarative_meta_flow_with_own_options_block(): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows([
                'qa' => ['jobs' => ['ok']],
                'ci' => ['flows' => ['qa'], 'options' => ['processes' => 2]],
            ])
            ->setV3Jobs(['ok' => ['type' => 'custom', 'script' => 'true']])
            ->setV3GlobalOptions(['history-size' => 30]);
        $this->configurationFileBuilder->buildInFileSystem();

        $this->artisan("flows ci --config=$this->configPath")->assertExitCode(0);

        $this->assertSame(1, $this->historyCount());
    }

    /**
     * BUG-34: `effectiveOptions` (JSON v2) exposes historySize with its source.
     * Its absence from the trace is what hid the silent reset to 0.
     *
     * @test
     */
    public function effective_options_json_exposes_history_size_with_source(): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['options' => ['processes' => 2], 'jobs' => ['ok']]])
            ->setV3Jobs(['ok' => ['type' => 'custom', 'script' => 'true']])
            ->setV3GlobalOptions(['history-size' => 30]);
        $this->configurationFileBuilder->buildInFileSystem();

        $output = $this->runJson("flow qa --format=json --config=$this->configPath");

        $this->assertSame(30, $output['effectiveOptions']['historySize']['value']);
        $this->assertSame('flows.options', $output['effectiveOptions']['historySize']['source']);
    }

    /**
     * Run a command whose stdout is JSON and decode it.
     *
     * @return array<string, mixed>
     */
    private function runJson(string $command): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'historyjson_');
        try {
            $this->artisan(trim("$command --output=$tmp"));
            $payload = (string) file_get_contents($tmp);
            $decoded = json_decode($payload, true);
            $this->assertIsArray($decoded, "Expected JSON output at $tmp, got:\n$payload");
            return $decoded;
        } finally {
            @unlink($tmp);
        }
    }
}
