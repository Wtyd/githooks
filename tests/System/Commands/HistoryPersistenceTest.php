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
}
