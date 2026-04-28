<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Execution\Memory\MemoryStats;
use Wtyd\GitHooks\Output\StatsTableRenderer;

class StatsTableRendererTest extends TestCase
{
    /** @test */
    public function it_renders_nothing_when_memory_stats_are_absent(): void
    {
        $output = new BufferedOutput();
        $result = new FlowResult('qa', [new JobResult('a', true, '', '1s')], '1s');

        (new StatsTableRenderer())->render($output, $result);

        $this->assertSame('', $output->fetch());
    }

    /** @test */
    public function it_renders_table_header_and_total_row_with_cores_and_memory(): void
    {
        $stats = new MemoryStats(
            true,
            900,
            5.5,
            ['phpstan' => 600, 'phpunit' => 300],
            ['phpstan' => 600, 'phpunit' => 300],
            10,
            5,
            5.5,
            ['phpstan', 'phpunit'],
            ['phpstan' => 4, 'phpunit' => 1]
        );
        $jobs = [
            (new JobResult('phpstan', true, '', '8.2s'))->withMemoryPeak(600),
            (new JobResult('phpunit', true, '', '4.1s'))->withMemoryPeak(300),
        ];
        $result = new FlowResult('qa', $jobs, '12.3s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertStringContainsString('Job', $rendered);
        $this->assertStringContainsString('Peak Cores', $rendered);
        $this->assertStringContainsString('Peak Memory', $rendered);
        $this->assertStringContainsString('phpstan', $rendered);
        $this->assertStringContainsString('phpunit', $rendered);
        $this->assertStringContainsString('600 MB', $rendered);
        $this->assertStringContainsString('300 MB', $rendered);
        $this->assertStringContainsString('TOTAL (flow)', $rendered);
        $this->assertStringContainsString('5/10', $rendered);
        $this->assertStringContainsString('900 MB', $rendered);
    }

    /** @test */
    public function it_renders_temporal_attribution_when_sampler_was_active(): void
    {
        $stats = new MemoryStats(
            true,
            450,
            8.2,
            ['a' => 200, 'b' => 250],
            ['a' => 200, 'b' => 250],
            4,
            2,
            8.2,
            ['a', 'b'],
            ['a' => 1, 'b' => 1]
        );
        $result = new FlowResult('qa', [
            (new JobResult('a', true, '', '1s'))->withMemoryPeak(200),
            (new JobResult('b', true, '', '1s'))->withMemoryPeak(250),
        ], '8.2s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertStringContainsString('Memory peak at 8.20s: a 200 + b 250', $rendered);
        $this->assertStringContainsString('Cores peak at 8.20s:  a + b', $rendered);
    }

    /** @test */
    public function it_emits_cores_attribution_only_when_sampler_inactive(): void
    {
        $stats = new MemoryStats(
            false,
            0,
            0.0,
            [],
            [],
            8,
            3,
            1.5,
            ['x', 'y', 'z'],
            ['x' => 1, 'y' => 1, 'z' => 1]
        );
        $result = new FlowResult('qa', [
            new JobResult('x', true, '', '1s'),
            new JobResult('y', true, '', '1s'),
            new JobResult('z', true, '', '1s'),
        ], '2s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertStringNotContainsString('Memory peak', $rendered);
        $this->assertStringContainsString('Cores peak at 1.50s:  x + y + z', $rendered);
        $this->assertStringContainsString('n/a', $rendered);
    }

    /** @test */
    public function status_column_marks_warned_and_failed_jobs(): void
    {
        $stats = new MemoryStats(
            true,
            2200,
            1.0,
            ['warned' => 800, 'failed' => 1400],
            ['warned' => 800, 'failed' => 1400],
            4,
            2,
            1.0,
            ['warned', 'failed'],
            ['warned' => 1, 'failed' => 1]
        );
        $jobs = [
            (new JobResult('warned', true, '', '1s'))
                ->withMemoryPeak(800)
                ->withMemoryThreshold(JobResult::MEMORY_THRESHOLD_WARNED, JobResult::MEMORY_REASON_WARN, 600, 1500),
            (new JobResult('failed', false, '', '1s'))
                ->withMemoryPeak(1400)
                ->withMemoryThreshold(JobResult::MEMORY_THRESHOLD_FAILED, JobResult::MEMORY_REASON_FAIL, 600, 1200),
        ];
        $result = new FlowResult('qa', $jobs, '1s');
        $result->setMemoryStats($stats);

        $output = new BufferedOutput();
        (new StatsTableRenderer())->render($output, $result);
        $rendered = $output->fetch();

        $this->assertStringContainsString('OK ⚠', $rendered);
        $this->assertStringContainsString('KO', $rendered);
    }
}
