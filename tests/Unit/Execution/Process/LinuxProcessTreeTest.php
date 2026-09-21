<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Process;

use Symfony\Component\Process\Process;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Execution\Process\LinuxProcessTree;

/**
 * LinuxProcessTree driven through a synthetic /proc map (any platform,
 * microseconds), plus one smoke test against the real /proc on Linux.
 * The walk semantics (depth cap, duplicates, cycles) are pinned here for
 * ProcessTreeWalk since this is its primary consumer.
 *
 * @group linux
 */
class LinuxProcessTreeTest extends UnitTestCase
{
    /** @test */
    public function descendants_are_listed_parents_before_children(): void
    {
        // 100 → {200, 250}; 200 → {300}
        $tree = $this->fakeTreeWith([
            '/proc/100/task/100/children' => '200 250',
            '/proc/200/task/200/children' => '300',
            '/proc/250/task/250/children' => '',
            '/proc/300/task/300/children' => '',
        ]);

        $this->assertSame([200, 250, 300], $tree->descendants(100));
    }

    /**
     * @test
     * @dataProvider nonPositiveRoots
     */
    public function non_positive_root_has_no_descendants_even_when_proc_would_answer(int $rootPid): void
    {
        $tree = $this->fakeTreeWith([
            "/proc/{$rootPid}/task/{$rootPid}/children" => '200',
        ]);

        $this->assertSame([], $tree->descendants($rootPid));
    }

    /** @return array<string, array{int}> */
    public function nonPositiveRoots(): array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }

    /** @test */
    public function pid_repeated_in_the_children_file_is_listed_once(): void
    {
        $tree = $this->fakeTreeWith([
            '/proc/100/task/100/children' => '200 200',
            '/proc/200/task/200/children' => '',
        ]);

        $this->assertSame([200], $tree->descendants(100));
    }

    /** @test */
    public function a_child_listing_its_own_ancestor_does_not_loop(): void
    {
        $tree = $this->fakeTreeWith([
            '/proc/100/task/100/children' => '200',
            '/proc/200/task/200/children' => '300 100',
            '/proc/300/task/300/children' => '200',
        ]);

        $this->assertSame([200, 300], $tree->descendants(100));
    }

    /** @test */
    public function walk_stops_expanding_at_max_tree_depth(): void
    {
        // Chain 100 → 101 → … → 118. Depth 0 is the root; nodes at depth 16
        // are listed but not expanded, so 101..116 are the descendants.
        $procMap = [];
        for ($pid = 100; $pid <= 118; $pid++) {
            $procMap["/proc/{$pid}/task/{$pid}/children"] = $pid < 118 ? (string) ($pid + 1) : '';
        }
        $tree = $this->fakeTreeWith($procMap);

        $this->assertSame(range(101, 116), $tree->descendants(100));
    }

    /** @test */
    public function missing_or_empty_children_file_means_no_descendants(): void
    {
        $tree = $this->fakeTreeWith(['/proc/100/task/100/children' => '']);

        $this->assertSame([], $tree->descendants(100));
        $this->assertSame([], $tree->descendants(999));
    }

    /** @test */
    public function non_numeric_tokens_in_the_children_file_are_ignored(): void
    {
        $tree = $this->fakeTreeWith([
            '/proc/100/task/100/children' => "200 abc  300\n",
            '/proc/200/task/200/children' => '',
            '/proc/300/task/300/children' => '',
        ]);

        $this->assertSame([200, 300], $tree->descendants(100));
    }

    /**
     * @test
     * @dataProvider statContents
     */
    public function alive_reads_the_state_after_the_last_closing_paren(?string $stat, bool $expectedAlive): void
    {
        $tree = $this->fakeTreeWith($stat === null ? [] : ['/proc/200/stat' => $stat]);

        $this->assertSame($expectedAlive ? [200] : [], $tree->alive([200]));
    }

    /** @return array<string, array{?string, bool}> */
    public function statContents(): array
    {
        return [
            'sleeping'                                 => ["200 (sleep) S 100 200 100 0 -1 4194560 89\n", true],
            'running'                                  => ['200 (php) R 100 200 100 0 -1', true],
            'uninterruptible io'                       => ['200 (php) D 100 200', true],
            'zombie'                                   => ['200 (sleep) Z 100 200 100 0 -1', false],
            'dead'                                     => ['200 (sleep) X 100 200', false],
            'zombie with spaces and parens in comm'    => ['200 (sh -c (x) y) Z 1 200', false],
            'alive with spaces and parens in comm'     => ['200 (sh -c (x) y) S 1 200', true],
            'stat file gone'                           => [null, false],
            'no closing paren'                         => ['garbage', false],
            'nothing after the closing paren'          => ['200 (sleep)', false],
        ];
    }

    /** @test */
    public function alive_filters_and_keeps_the_input_order(): void
    {
        $tree = $this->fakeTreeWith([
            '/proc/300/stat' => '300 (a) S 1 300',
            '/proc/200/stat' => '200 (b) Z 1 200',
            '/proc/100/stat' => '100 (c) R 1 100',
        ]);

        $this->assertSame([300, 100], $tree->alive([300, 200, 100, 999]));
        $this->assertSame([], $tree->alive([]));
    }

    /** @test */
    public function it_reports_available(): void
    {
        $tree = new LinuxProcessTree();

        $this->assertTrue($tree->isAvailable());
        $this->assertSame('', $tree->getUnavailableReason());
    }

    /**
     * @test
     * Real /proc: the wrapper Symfony spawns forks the inner shell and the
     * sleeper; both must show up as descendants and as alive, and vanish
     * from alive() once killed.
     */
    public function it_walks_a_real_process_tree_under_proc(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_dir('/proc') || !function_exists('posix_kill')) {
            $this->markTestSkipped('needs Linux with /proc and ext-posix');
        }

        $tree = new LinuxProcessTree();
        $process = Process::fromShellCommandLine("sh -c 'sleep 5; true'");
        $process->start();
        $root = (int) $process->getPid();
        $descendants = [];
        $deadline = microtime(true) + 3.0;
        while ($descendants === [] && microtime(true) < $deadline) {
            usleep(20000);
            $descendants = $tree->descendants($root);
        }

        try {
            $this->assertNotEmpty($descendants, 'the shell never forked a child');
            $this->assertSame($descendants, $tree->alive($descendants));
            $this->assertSame([], $tree->alive([9999999]));
        } finally {
            foreach (array_reverse($descendants) as $pid) {
                posix_kill($pid, 9);
            }
            $process->stop(0);
        }
    }

    /**
     * @param array<string, string> $procMap
     */
    private function fakeTreeWith(array $procMap): LinuxProcessTree
    {
        return new class ($procMap) extends LinuxProcessTree {
            /** @var array<string, string> */
            private $procMap;

            public function __construct(array $procMap)
            {
                $this->procMap = $procMap;
            }

            protected function readProcFile(string $path): ?string
            {
                return $this->procMap[$path] ?? null;
            }
        };
    }
}
