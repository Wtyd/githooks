<?php

declare(strict_types=1);

namespace Tests\Unit\Output\Inspection;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Output\Inspection\SystemInfo;

/**
 * Factor table B (see factors.md): SystemInfo::status() classifies the relation
 * between `processes` and `cpus`. The pathogenic class is `processes == cpus`
 * (a `>` → `>=` mutant would mislabel it as a warning).
 */
class SystemInfoTest extends UnitTestCase
{
    /**
     * @test
     * @dataProvider statusCases
     */
    public function status_classifies_processes_against_cpus(int $cpus, ?int $processes, string $expected)
    {
        $info = new SystemInfo($cpus, $processes);

        $this->assertSame($expected, $info->status());
    }

    public function statusCases(): array
    {
        return [
            // cpus, processes, expected status
            'no config (processes null)'                  => [8, null, SystemInfo::STATUS_NO_CONFIG],
            'processes > cpus (over-subscription)'        => [4, 5, SystemInfo::STATUS_WARNING],
            'processes == cpus (boundary, fits → ok)'     => [4, 4, SystemInfo::STATUS_OK],
            'processes == cpus + 1 (boundary, warning)'   => [4, 5, SystemInfo::STATUS_WARNING],
            'processes == 1 (under-utilising → tip)'      => [4, 1, SystemInfo::STATUS_TIP],
            'processes == 1 and cpus == 1 (tip)'          => [1, 1, SystemInfo::STATUS_TIP],
            '2 <= processes < cpus (fits → ok)'           => [8, 2, SystemInfo::STATUS_OK],
        ];
    }

    /**
     * @test
     * @dataProvider warningPresenceCases
     */
    public function warning_is_present_only_on_over_subscription(int $cpus, ?int $processes, bool $hasWarning)
    {
        $info = new SystemInfo($cpus, $processes);

        $this->assertSame($hasWarning, $info->warning() !== null);
    }

    public function warningPresenceCases(): array
    {
        return [
            'no config → no warning'              => [8, null, false],
            'within budget (ok) → no warning'     => [8, 2, false],
            'boundary (processes == cpus) → none' => [4, 4, false],
            'tip → no warning'                    => [4, 1, false],
            'over-subscription → warning'         => [4, 5, true],
        ];
    }

    /** @test */
    public function warning_message_is_exact()
    {
        $info = new SystemInfo(4, 9);

        $this->assertSame(
            "'processes' (9) exceeds available CPUs (4). This may saturate the machine.",
            $info->warning()
        );
    }

    /** @test */
    public function getters_return_constructor_values()
    {
        $info = new SystemInfo(12, 6);

        $this->assertSame(12, $info->getCpus());
        $this->assertSame(6, $info->getProcesses());
    }
}
