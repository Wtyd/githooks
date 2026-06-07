<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Memory;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Execution\Memory\MacOsRssSampler;

/**
 * @group macos
 *
 * Real ps invocation; only runs on macOS. The Linux CI matrix excludes
 * @group macos via phpunit.xml; the macOS leg (when added) opts in.
 */
class MacOsRssSamplerIntegrationTest extends UnitTestCase
{
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('MacOsRssSampler integration test requires macOS');
        }
    }

    /** @test */
    public function it_reads_a_positive_rss_value_for_the_current_php_process(): void
    {
        $sampler = new MacOsRssSampler();

        $samples = $sampler->sample(['self' => getmypid()]);

        $this->assertArrayHasKey('self', $samples);
        $this->assertGreaterThan(0, $samples['self']);
        $this->assertLessThan(2048, $samples['self'], 'PHPUnit RSS should fit in 2 GB');
    }

    /** @test */
    public function it_silently_skips_pids_that_do_not_exist(): void
    {
        $sampler = new MacOsRssSampler();

        $samples = $sampler->sample([
            'alive' => getmypid(),
            'gone'  => 9999999,
        ]);

        $this->assertArrayHasKey('alive', $samples);
        $this->assertArrayNotHasKey('gone', $samples);
    }
}
