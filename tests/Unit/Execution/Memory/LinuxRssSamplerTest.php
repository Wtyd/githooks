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

    /**
     * @test
     * Regression: the sampler used to read only the root PID, which under
     * Symfony's `Process::fromShellCommandLine()` is the shell wrapper —
     * a few MB. The actual analyzer (php phpstan, php phpunit) is a child
     * process and only contributes when the tree is summed.
     */
    public function it_sums_rss_across_the_process_tree(): void
    {
        // Spawn a shell that forks a PHP child holding a measurable buffer.
        // The shell itself is ~1-2 MB; the php child should add ≥ ~20 MB.
        $cmd = 'sh -c "php -r \'\\$a=str_repeat(chr(65),1024*1024*30); usleep(800000);\' & wait"';
        $proc = \Symfony\Component\Process\Process::fromShellCommandLine($cmd);
        $proc->start();

        // Give the fork a moment to allocate before sampling.
        usleep(300000);

        $sampler = new LinuxRssSampler();
        $samples = $sampler->sample(['child' => $proc->getPid()]);

        $proc->wait();

        $this->assertArrayHasKey('child', $samples);
        $this->assertGreaterThan(
            10,
            $samples['child'],
            'Tree sum should reflect the child PHP buffer, not just the shell.'
        );
    }
}
