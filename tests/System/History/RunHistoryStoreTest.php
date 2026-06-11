<?php

declare(strict_types=1);

namespace Tests\System\History;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\History\RunHistoryStore;
use Wtyd\GitHooks\Utils\Storage;

/**
 * FEAT-5 · persistence and FIFO rotation. The store only honours the `enabled`
 * flag handed by the runner; activation/dry-run logic lives in the runner and
 * is exercised by the persistence factor-table test.
 */
class RunHistoryStoreTest extends SystemTestCase
{
    private RunHistoryStore $store;

    private const STARTED_AT = '2026-06-10T14:30:05+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new RunHistoryStore();
    }

    private function result(string $flowName = 'qa'): FlowResult
    {
        return new FlowResult($flowName, [], '1.50s');
    }

    /**
     * @return string[] basenames present for a slug
     */
    private function files(string $slug): array
    {
        $names = [];
        foreach (Storage::files(RunHistoryStore::HISTORY_DIR) as $file) {
            if (RunHistoryStore::matchesFlow(basename($file), $slug)) {
                $names[] = basename($file);
            }
        }
        sort($names);
        return $names;
    }

    /** @test */
    public function it_persists_the_payload_under_a_timestamped_filename(): void
    {
        $path = $this->store->persist($this->result(), self::STARTED_AT, true, 5);

        $this->assertNotNull($path);
        $this->assertSame(RunHistoryStore::HISTORY_DIR . '/20260610-143005-qa.json', $path);
        $decoded = json_decode(Storage::get($path), true);
        $this->assertSame('qa', $decoded['flow']);
        $this->assertSame(2, $decoded['version']);
    }

    /** @test */
    public function it_is_a_noop_when_disabled_or_size_not_positive(): void
    {
        $this->assertNull($this->store->persist($this->result(), self::STARTED_AT, false, 5));
        $this->assertNull($this->store->persist($this->result(), self::STARTED_AT, true, 0));
        $this->assertSame([], $this->files('qa'));
    }

    /** @test */
    public function same_second_runs_do_not_overwrite_each_other(): void
    {
        $this->store->persist($this->result(), self::STARTED_AT, true, 5);
        $this->store->persist($this->result(), self::STARTED_AT, true, 5);

        $this->assertCount(2, $this->files('qa'));
    }

    /** @test */
    public function rotation_keeps_only_the_newest_n_runs_per_flow(): void
    {
        // Seed four older runs, then persist one newer with size 3.
        foreach (['20260101-100000', '20260102-100000', '20260103-100000', '20260104-100000'] as $ts) {
            Storage::put(RunHistoryStore::HISTORY_DIR . "/$ts-qa.json", '{"flow":"qa"}');
        }

        $this->store->persist($this->result(), self::STARTED_AT, true, 3);

        $files = $this->files('qa');
        $this->assertCount(3, $files);
        $this->assertContains('20260610-143005-qa.json', $files); // newest kept
        $this->assertNotContains('20260101-100000-qa.json', $files); // oldest dropped
        $this->assertNotContains('20260102-100000-qa.json', $files);
    }

    /** @test */
    public function rotation_is_independent_per_flow_slug(): void
    {
        Storage::put(RunHistoryStore::HISTORY_DIR . '/20260101-100000-tests.json', '{"flow":"tests"}');

        $this->store->persist($this->result('qa'), self::STARTED_AT, true, 1);

        $this->assertCount(1, $this->files('qa'));
        $this->assertCount(1, $this->files('tests')); // untouched
    }

    /** @test */
    public function multiflow_name_is_sanitised_into_the_filename(): void
    {
        $path = $this->store->persist($this->result('a+b'), self::STARTED_AT, true, 5);

        $this->assertSame(RunHistoryStore::HISTORY_DIR . '/20260610-143005-a+b.json', $path);
    }
}
