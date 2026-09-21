<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Process;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Execution\Process\MacOsProcessTree;

/**
 * MacOsProcessTree driven through synthetic `ps` output keyed by the exact
 * command it must issue, so the tests pin both the parsing and the
 * commands themselves — runs on any platform without invoking ps.
 */
class MacOsProcessTreeTest extends UnitTestCase
{
    /** @test */
    public function descendants_are_rebuilt_from_a_pid_ppid_listing(): void
    {
        // 100 → {200, 400}; 200 → {300}; 999 is unrelated.
        $tree = $this->fakeTreeWith([
            'ps -o pid=,ppid= -ax' => "  100     1\n  200   100\n  300   200\n  400   100\n  999     1\n",
        ]);

        $this->assertSame([200, 400, 300], $tree->descendants(100));
        $this->assertSame(['ps -o pid=,ppid= -ax'], $tree->commands);
    }

    /** @test */
    public function malformed_listing_lines_are_skipped(): void
    {
        $tree = $this->fakeTreeWith([
            'ps -o pid=,ppid= -ax' => "  200   100\nPID PPID\n  300   100 extra\n\n  400   100\n",
        ]);

        $this->assertSame([200, 400], $tree->descendants(100));
    }

    /** @test */
    public function ps_failure_yields_no_descendants(): void
    {
        $tree = $this->fakeTreeWith([]);

        $this->assertSame([], $tree->descendants(100));
    }

    /**
     * @test
     * @dataProvider nonPositiveRoots
     */
    public function non_positive_root_does_not_invoke_ps(int $rootPid): void
    {
        $tree = $this->fakeTreeWith(['ps -o pid=,ppid= -ax' => "  200   {$rootPid}\n"]);

        $this->assertSame([], $tree->descendants($rootPid));
        $this->assertSame([], $tree->commands);
    }

    /** @return array<string, array{int}> */
    public function nonPositiveRoots(): array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }

    /** @test */
    public function alive_asks_ps_for_the_given_pids_and_drops_zombies_and_missing_ones(): void
    {
        $tree = $this->fakeTreeWith([
            'ps -o pid=,stat= -p 200,300,400' => "  200 S+\n  300 Z\n",
        ]);

        $this->assertSame([200], $tree->alive([200, 300, 400]));
        $this->assertSame(['ps -o pid=,stat= -p 200,300,400'], $tree->commands);
    }

    /** @test */
    public function alive_with_no_pids_does_not_invoke_ps(): void
    {
        $tree = $this->fakeTreeWith([]);

        $this->assertSame([], $tree->alive([]));
        $this->assertSame([], $tree->commands);
    }

    /** @test */
    public function alive_treats_a_ps_failure_as_everyone_gone(): void
    {
        $tree = $this->fakeTreeWith([]);

        $this->assertSame([], $tree->alive([200]));
    }

    /** @test */
    public function it_reports_available(): void
    {
        $tree = new MacOsProcessTree();

        $this->assertTrue($tree->isAvailable());
        $this->assertSame('', $tree->getUnavailableReason());
    }

    /**
     * @param array<string, string> $responses command → stdout
     * @return MacOsProcessTree&object{commands: string[]}
     */
    private function fakeTreeWith(array $responses): MacOsProcessTree
    {
        return new class ($responses) extends MacOsProcessTree {
            /** @var array<string, string> */
            private $responses;

            /** @var string[] */
            public $commands = [];

            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            protected function runProcessListing(string $command): ?string
            {
                $this->commands[] = $command;
                return $this->responses[$command] ?? null;
            }
        };
    }
}
