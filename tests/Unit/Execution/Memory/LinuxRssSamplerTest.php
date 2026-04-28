<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Memory;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\Memory\LinuxRssSampler;

/**
 * @group linux
 */
class LinuxRssSamplerTest extends TestCase
{
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_dir('/proc')) {
            $this->markTestSkipped('LinuxRssSampler requires Linux with /proc mounted');
        }
    }

    /** @test */
    public function it_reads_a_positive_rss_value_for_the_current_php_process(): void
    {
        $sampler = new LinuxRssSampler();
        $samples = $sampler->sample(['self' => getmypid()]);

        $this->assertArrayHasKey('self', $samples);
        $this->assertGreaterThan(0, $samples['self']);
        $this->assertLessThan(2048, $samples['self'], 'PHPUnit RSS should fit in 2 GB');
    }

    /** @test */
    public function it_silently_skips_pids_that_do_not_exist(): void
    {
        $sampler = new LinuxRssSampler();
        $samples = $sampler->sample([
            'alive' => getmypid(),
            'gone'  => 9999999,
        ]);

        $this->assertArrayHasKey('alive', $samples);
        $this->assertArrayNotHasKey('gone', $samples);
    }

    /** @test */
    public function it_returns_empty_for_empty_pid_set(): void
    {
        $sampler = new LinuxRssSampler();

        $this->assertSame([], $sampler->sample([]));
    }

    /** @test */
    public function it_skips_non_positive_pids(): void
    {
        $sampler = new LinuxRssSampler();
        $samples = $sampler->sample(['zero' => 0, 'negative' => -1]);

        $this->assertSame([], $samples);
    }

    /** @test */
    public function it_reports_available(): void
    {
        $sampler = new LinuxRssSampler();

        $this->assertTrue($sampler->isAvailable());
        $this->assertSame('', $sampler->getUnavailableReason());
    }
}
