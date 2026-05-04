<?php

declare(strict_types=1);

namespace Tests\Unit\Output\CI;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\CI\GitLabCIDecorator;
use Wtyd\GitHooks\Output\OutputHandler;

/**
 * The decorator buffers each job's body and emits the collapsible section
 * atomically on close, so parallel jobs cannot interleave their section
 * boundaries (which GitLab does not support).
 *
 * Design factors covered:
 *  - close state: OK / KO / SKIPPED
 *  - collapsed flag: true for OK & SKIPPED, false for KO and Errors flush
 *  - inner.flush() suppression so framed errors don't leak outside sections
 *  - parallel interleaving (start A → start B → end B → end A): no overlap
 */
class GitLabCIDecoratorTest extends TestCase
{
    /** @test */
    public function ok_section_is_emitted_atomically_with_collapsed_true_on_success()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobStart')->with('phpstan_src');
        $inner->expects($this->once())->method('onJobSuccess')->with('phpstan_src', '1.23s');

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobStart('phpstan_src');
        $duringJob = ob_get_clean();

        ob_start();
        $decorator->onJobSuccess('phpstan_src', '1.23s');
        $output = ob_get_clean();

        // Nothing must reach stdout while the job is active — only on close.
        $this->assertSame('', $duringJob, 'No output is expected before the job closes');

        $this->assertStringContainsString('section_start:', $output);
        $this->assertStringContainsString('[collapsed=true]', $output);
        $this->assertStringContainsString('section_end:', $output);
        $this->assertStringContainsString('phpstan_src', $output);
    }

    /** @test */
    public function ko_section_is_collapsed_false_and_includes_tool_output()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobStart');
        $inner->expects($this->once())->method('onJobError')
            ->with('phpstan_src', '500ms', "Line 42: undefined method foo()\n");

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobStart('phpstan_src');
        $decorator->onJobError('phpstan_src', '500ms', "Line 42: undefined method foo()\n");
        $output = ob_get_clean();

        $this->assertStringContainsString('[collapsed=false]', $output);
        $this->assertStringContainsString('Line 42: undefined method foo()', $output);
        $this->assertStringContainsString('section_start:', $output);
        $this->assertStringContainsString('section_end:', $output);
    }

    /** @test */
    public function ko_section_omits_error_block_when_tool_output_is_blank()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->method('onJobError');

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobError('phpstan_src', '500ms', "   \n  ");
        $output = ob_get_clean();

        $this->assertStringContainsString('[collapsed=false]', $output);
        // Body has just the section header line + whatever inner produced.
        $this->assertStringNotContainsString('   ', substr($output, strpos($output, "phpstan_src\n") + 12));
    }

    /** @test */
    public function skipped_section_uses_collapsed_true()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onJobSkipped')->with('phpcs', 'no staged files');

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobSkipped('phpcs', 'no staged files');
        $output = ob_get_clean();

        $this->assertStringContainsString('[collapsed=true]', $output);
        $this->assertStringContainsString('phpcs', $output);
    }

    /** @test */
    public function inner_flush_output_is_suppressed_to_avoid_duplicate_error_blocks()
    {
        $inner = new class implements OutputHandler {
            public function onFlowStart(int $totalJobs): void
            {
            }
            public function onJobStart(string $jobName): void
            {
            }
            public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
            {
            }
            public function onJobSuccess(string $jobName, string $time): void
            {
            }
            public function onJobError(string $jobName, string $time, string $output): void
            {
            }
            public function onJobSkipped(string $jobName, string $reason): void
            {
            }
            public function onJobDryRun(string $jobName, string $command): void
            {
            }
            public function flush(): void
            {
                echo "framed error block (should be suppressed)\n";
            }
        };

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->flush();
        $output = ob_get_clean();

        $this->assertSame('', $output, 'inner.flush() output must not leak outside any section');
    }

    /** @test */
    public function parallel_job_interleaving_still_emits_non_overlapping_sections()
    {
        // Simulates parallel execution: A and B start interleaved, then close
        // out-of-order. Each section must open AND close in one shot, never
        // straddling another section.
        $inner = $this->createMock(OutputHandler::class);

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobStart('A');
        $decorator->onJobStart('B');
        $decorator->onJobSuccess('B', '1s');
        $decorator->onJobSuccess('A', '2s');
        $output = ob_get_clean();

        // Two complete sections: B's first (closed first), then A's.
        $this->assertSame(
            2,
            substr_count($output, 'section_start:'),
            'Exactly two section_start markers'
        );
        $this->assertSame(
            2,
            substr_count($output, 'section_end:'),
            'Exactly two section_end markers'
        );

        // Critical invariant: no nesting. Each section_start is followed by
        // its matching section_end before the next section_start.
        $tokens = preg_split('/\R/', $output) ?: [];
        $stack = 0;
        foreach ($tokens as $line) {
            if (strpos($line, 'section_start:') !== false) {
                $stack++;
                $this->assertSame(1, $stack, "Sections must not overlap (line: $line)");
            }
            if (strpos($line, 'section_end:') !== false) {
                $stack--;
            }
        }
        $this->assertSame(0, $stack, 'All sections must be closed');
    }

    /** @test */
    public function each_section_uses_a_unique_id()
    {
        $inner = $this->createMock(OutputHandler::class);
        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobSuccess('job1', '1s');
        $decorator->onJobSuccess('job2', '1s');
        $output = ob_get_clean();

        $this->assertStringContainsString('githooks_job_1', $output);
        $this->assertStringContainsString('githooks_job_2', $output);
    }

    /** @test */
    public function it_delegates_pass_through_methods()
    {
        $inner = $this->createMock(OutputHandler::class);
        $inner->expects($this->once())->method('onFlowStart')->with(3);
        $inner->expects($this->once())->method('onJobDryRun')->with('phpcs', 'php phpcs.phar');

        $decorator = new GitLabCIDecorator($inner);
        $decorator->onFlowStart(3);

        ob_start();
        $decorator->onJobDryRun('phpcs', 'php phpcs.phar');
        ob_end_clean();
    }

    /** @test */
    public function inner_streamed_output_lands_inside_the_section_body()
    {
        // Simulates an inner handler that streams via echo (e.g. StreamingTextOutputHandler).
        $inner = new class implements OutputHandler {
            public function onFlowStart(int $totalJobs): void
            {
            }
            public function onJobStart(string $jobName): void
            {
                echo "  --- $jobName ---\n";
            }
            public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
            {
                echo $chunk;
            }
            public function onJobSuccess(string $jobName, string $time): void
            {
                echo "  $jobName OK $time\n";
            }
            public function onJobError(string $jobName, string $time, string $output): void
            {
            }
            public function onJobSkipped(string $jobName, string $reason): void
            {
            }
            public function onJobDryRun(string $jobName, string $command): void
            {
            }
            public function flush(): void
            {
            }
        };

        $decorator = new GitLabCIDecorator($inner);

        ob_start();
        $decorator->onJobStart('phpstan');
        $decorator->onJobOutput('phpstan', "running...\n", false);
        $decorator->onJobSuccess('phpstan', '0.5s');
        $output = ob_get_clean();

        // Section body must include all streamed content from the inner handler.
        $startIdx = strpos($output, 'phpstan');
        $endIdx = strpos($output, 'section_end:');
        $body = substr($output, (int) $startIdx, (int) $endIdx - (int) $startIdx);

        $this->assertStringContainsString('--- phpstan ---', $body);
        $this->assertStringContainsString('running...', $body);
        $this->assertStringContainsString('phpstan OK 0.5s', $body);
    }
}
