<?php

declare(strict_types=1);

namespace Tests\Unit\Output\Inspection;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Output\Inspection\SystemInfo;
use Wtyd\GitHooks\Output\Inspection\SystemInfoJsonFormatter;

class SystemInfoJsonFormatterTest extends UnitTestCase
{
    /** @test */
    public function it_serialises_within_budget_with_null_warning()
    {
        $json = (new SystemInfoJsonFormatter())->format(new SystemInfo(8, 4));

        $this->assertSame(
            ['version' => 1, 'cpus' => 8, 'processes' => 4, 'warning' => null],
            json_decode($json, true)
        );
    }

    /** @test */
    public function it_serialises_over_subscription_with_warning_message()
    {
        $json = (new SystemInfoJsonFormatter())->format(new SystemInfo(4, 9));

        $this->assertSame(
            [
                'version' => 1,
                'cpus' => 4,
                'processes' => 9,
                'warning' => "'processes' (9) exceeds available CPUs (4). This may saturate the machine.",
            ],
            json_decode($json, true)
        );
    }

    /** @test */
    public function it_serialises_no_config_with_null_processes_and_warning()
    {
        $json = (new SystemInfoJsonFormatter())->format(new SystemInfo(8, null));

        $this->assertSame(
            ['version' => 1, 'cpus' => 8, 'processes' => null, 'warning' => null],
            json_decode($json, true)
        );
    }
}
