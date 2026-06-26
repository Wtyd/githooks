<?php

declare(strict_types=1);

namespace Tests\Unit\Output\Inspection;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Hooks\HookEventStatus;
use Wtyd\GitHooks\Hooks\HookStatusReport;
use Wtyd\GitHooks\Output\Inspection\StatusJsonFormatter;

class StatusJsonFormatterTest extends UnitTestCase
{
    /** @test */
    public function it_serialises_hooks_path_and_events_with_full_payload()
    {
        $report = new HookStatusReport(true, '.githooks', [
            new HookEventStatus('pre-commit', HookEventStatus::STATUS_SYNCED, true, ['qa']),
            new HookEventStatus('pre-push', HookEventStatus::STATUS_MISSING, false, ['security', 'qa']),
        ]);

        $payload = json_decode((new StatusJsonFormatter())->format($report), true);

        $this->assertSame([
            'version' => 1,
            'hooksPath' => ['configured' => true, 'value' => '.githooks'],
            'events' => [
                ['event' => 'pre-commit', 'status' => 'synced', 'executable' => true, 'targets' => ['qa']],
                ['event' => 'pre-push', 'status' => 'missing', 'executable' => false, 'targets' => ['security', 'qa']],
            ],
        ], $payload);
    }

    /**
     * Factor table D: targets are emitted raw across every status — the text
     * renderer's placeholders (`—`, `(not in configuration)`) are presentation,
     * never leak into JSON. An orphan with no targets stays an empty array.
     *
     * @test
     * @dataProvider rawTargetsCases
     */
    public function targets_are_emitted_raw_for_every_status(string $status, array $targets, array $expected)
    {
        $report = new HookStatusReport(false, '', [
            new HookEventStatus('pre-commit', $status, true, $targets),
        ]);

        $payload = json_decode((new StatusJsonFormatter())->format($report), true);

        $this->assertSame($expected, $payload['events'][0]['targets']);
    }

    public function rawTargetsCases(): array
    {
        return [
            'synced with targets'   => [HookEventStatus::STATUS_SYNCED, ['qa'], ['qa']],
            'missing with targets'  => [HookEventStatus::STATUS_MISSING, ['qa'], ['qa']],
            'orphan with targets'   => [HookEventStatus::STATUS_ORPHAN, ['qa'], ['qa']],
            'synced empty → []'     => [HookEventStatus::STATUS_SYNCED, [], []],
            'orphan empty → [] (no placeholder)' => [HookEventStatus::STATUS_ORPHAN, [], []],
        ];
    }

    /** @test */
    public function it_serialises_empty_events_as_empty_array()
    {
        $report = new HookStatusReport(false, '', []);

        $payload = json_decode((new StatusJsonFormatter())->format($report), true);

        $this->assertSame([], $payload['events']);
        $this->assertSame(['configured' => false, 'value' => ''], $payload['hooksPath']);
    }
}
