<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Memory;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\Memory\LinuxRssSampler;

/**
 * Parser-only tests for LinuxRssSampler. They drive the sampler through
 * a subclass that bypasses real /proc reads, so they run on any platform
 * and in microseconds. The integration test that actually walks /proc
 * lives in LinuxRssSamplerIntegrationTest with @group integration.
 *
 * The 5 smoke tests below (it_reads_a_positive_rss_value_for_the_current_php_process,
 * it_silently_skips_pids_that_do_not_exist, etc.) keep instantiating the
 * real LinuxRssSampler and require Linux to run — they verify the binding
 * to /proc still works on hosts that have it, and skip elsewhere.
 *
 * @group linux
 */
class LinuxRssSamplerTest extends TestCase
{
    // ========================================================================
    // Smoke tests against the real /proc — Linux only
    // ========================================================================

    /** @test */
    public function it_reads_a_positive_rss_value_for_the_current_php_process(): void
    {
        $this->skipUnlessLinuxProc();

        $sampler = new LinuxRssSampler();
        $samples = $sampler->sample(['self' => getmypid()]);

        $this->assertArrayHasKey('self', $samples);
        $this->assertGreaterThan(0, $samples['self']);
        $this->assertLessThan(2048, $samples['self'], 'PHPUnit RSS should fit in 2 GB');
    }

    /** @test */
    public function it_silently_skips_pids_that_do_not_exist(): void
    {
        $this->skipUnlessLinuxProc();

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

    // ========================================================================
    // Parser tests against synthetic /proc — cross-platform, in-memory
    // ========================================================================

    /** @test */
    public function it_sums_rss_across_descendants_at_arbitrary_depth(): void
    {
        // Tree rooted at 100:
        //   100 (root, 4 MB) -> 200 (1 MB) -> 300 (8 MB) -> 400 (16 MB)
        //                    -> 250 (2 MB)
        $sampler = $this->fakeSamplerWith([
            '/proc/100/status'                  => $this->statusWithRss(4096),
            '/proc/100/task/100/children'       => '200 250',
            '/proc/200/status'                  => $this->statusWithRss(1024),
            '/proc/200/task/200/children'       => '300',
            '/proc/250/status'                  => $this->statusWithRss(2048),
            '/proc/250/task/250/children'       => '',
            '/proc/300/status'                  => $this->statusWithRss(8192),
            '/proc/300/task/300/children'       => '400',
            '/proc/400/status'                  => $this->statusWithRss(16384),
            '/proc/400/task/400/children'       => '',
        ]);

        $samples = $sampler->sample(['root' => 100]);

        $this->assertSame(31, $samples['root']); // (4096+1024+2048+8192+16384) / 1024
    }

    /** @test */
    public function it_does_not_double_count_when_a_child_appears_under_two_parents(): void
    {
        // Pathological: a PID listed twice in the children file.
        // The visited set must prevent double counting.
        $sampler = $this->fakeSamplerWith([
            '/proc/100/status'            => $this->statusWithRss(1024),
            '/proc/100/task/100/children' => '200 200',
            '/proc/200/status'            => $this->statusWithRss(2048),
            '/proc/200/task/200/children' => '',
        ]);

        $samples = $sampler->sample(['root' => 100]);

        $this->assertSame(3, $samples['root']); // (1024+2048) / 1024, not (1024+2048+2048)
    }

    /** @test */
    public function it_handles_multiple_root_pids_in_a_single_call(): void
    {
        // Two independent jobs: 100->101 and 200->201
        $sampler = $this->fakeSamplerWith([
            '/proc/100/status'            => $this->statusWithRss(1024),
            '/proc/100/task/100/children' => '101',
            '/proc/101/status'            => $this->statusWithRss(2048),
            '/proc/101/task/101/children' => '',
            '/proc/200/status'            => $this->statusWithRss(3072),
            '/proc/200/task/200/children' => '201',
            '/proc/201/status'            => $this->statusWithRss(4096),
            '/proc/201/task/201/children' => '',
        ]);

        $samples = $sampler->sample(['a' => 100, 'b' => 200]);

        $this->assertSame(3, $samples['a']); // 1024 + 2048 = 3072 kB -> 3 MB
        $this->assertSame(7, $samples['b']); // 3072 + 4096 = 7168 kB -> 7 MB
    }

    /** @test */
    public function it_caps_tree_walk_at_max_tree_depth(): void
    {
        // Linear chain 100 -> 101 -> ... -> 118 (19 PIDs, depth 0..18).
        // MAX_TREE_DEPTH = 16 means depths 0..16 are summed (17 levels);
        // depth 17 and beyond must NOT contribute.
        $procMap = [];
        for ($i = 0; $i <= 18; $i++) {
            $pid = 100 + $i;
            $next = ($i < 18) ? (string) ($pid + 1) : '';
            $procMap["/proc/{$pid}/status"]                  = $this->statusWithRss(1024);
            $procMap["/proc/{$pid}/task/{$pid}/children"]    = $next;
        }
        $sampler = $this->fakeSamplerWith($procMap);

        $samples = $sampler->sample(['root' => 100]);

        // 17 levels x 1024 kB = 17408 kB -> 17 MB.
        $this->assertSame(17, $samples['root']);
    }

    /** @test */
    public function it_omits_descendants_with_no_vmrss_line(): void
    {
        // Middle child has no VmRSS in its status (e.g. zombie / kernel
        // thread). It must be skipped silently; the rest of the tree
        // still contributes.
        $sampler = $this->fakeSamplerWith([
            '/proc/100/status'            => $this->statusWithRss(1024),
            '/proc/100/task/100/children' => '200',
            '/proc/200/status'            => "Name:\tzombie\nState:\tZ\n", // no VmRSS line
            '/proc/200/task/200/children' => '300',
            '/proc/300/status'            => $this->statusWithRss(2048),
            '/proc/300/task/300/children' => '',
        ]);

        $samples = $sampler->sample(['root' => 100]);

        $this->assertSame(3, $samples['root']); // 1024 + 2048 = 3072 kB -> 3 MB
    }

    /** @test */
    public function it_returns_no_entry_when_root_pid_has_no_status(): void
    {
        $sampler = $this->fakeSamplerWith([
            // No /proc/100/status entry at all -> root unreadable.
        ]);

        $samples = $sampler->sample(['gone' => 100]);

        $this->assertSame([], $samples);
    }

    /** @test */
    public function it_rejects_root_pid_zero_even_when_proc_zero_status_is_readable(): void
    {
        // Kills LessThanOrEqualTo `<= 0` -> `< 0` mutant on the early
        // guard at line 53, plus the ReturnRemoval mutant on line 54:
        // even with a perfectly readable /proc/0/status, pid=0 must be
        // rejected by the early-return guard before any /proc read.
        $sampler = $this->fakeSamplerWith([
            '/proc/0/status'            => $this->statusWithRss(8192),
            '/proc/0/task/0/children'   => '',
        ]);

        $samples = $sampler->sample(['kernel' => 0]);

        $this->assertSame([], $samples);
    }

    /** @test */
    public function it_does_not_double_count_when_root_pid_appears_in_its_own_children(): void
    {
        // Kills ArrayItemRemoval / TrueValue mutants on the
        // `$visited = [$rootPid => true]` initialiser at line 64:
        // without the root in the visited set, a self-referential tree
        // (or a kernel re-parenting cycle) would re-visit and double-count
        // the root's RSS.
        $sampler = $this->fakeSamplerWith([
            '/proc/100/status'            => $this->statusWithRss(4096),
            '/proc/100/task/100/children' => '100',
        ]);

        $samples = $sampler->sample(['cyclic' => 100]);

        $this->assertSame(4, $samples['cyclic']); // 4096 kB / 1024, not 8192
    }

    /** @test */
    public function rss_total_uses_kb_to_mb_divisor_of_1024(): void
    {
        // Kills DecrementInteger on the `/ 1024` divisor at line 84:
        // a value of 1023 kB rounds to 0 MB (1023/1024 < 1) under the
        // original divisor; with `/ 1023` the mutant would return 1.
        $sampler = $this->fakeSamplerWith([
            '/proc/100/status'            => $this->statusWithRss(1023),
            '/proc/100/task/100/children' => '',
        ]);

        $samples = $sampler->sample(['under_one_mb' => 100]);

        $this->assertSame(0, $samples['under_one_mb']);
    }

    /** @test */
    public function vmrss_regex_only_matches_at_line_start(): void
    {
        // Kills PregMatchRemoveCaret on the VmRSS regex at line 103:
        // without the `^` anchor, a line like "OtherVmRSS: 9999 kB"
        // would match before the real "VmRSS: ..." line.
        $sampler = $this->fakeSamplerWith([
            '/proc/100/status' =>
                "Name:\ttest\nNonRssDataVmRSS:\t   9999 kB\nVmRSS:\t   2048 kB\n",
            '/proc/100/task/100/children' => '',
        ]);

        $samples = $sampler->sample(['real' => 100]);

        // 2048 kB / 1024 = 2 MB. With the caret removed the regex would
        // match the bogus 9999 kB first and produce 9 MB.
        $this->assertSame(2, $samples['real']);
    }

    /** @test */
    public function read_children_returns_empty_for_blank_content(): void
    {
        // Kills ReturnRemoval on the `return [];` early-out at line 120
        // for empty / null children content. With the return removed,
        // the function would fall through to preg_split('/\s+/', '')
        // which yields [''] and would then error or pollute the queue
        // with non-numeric tokens. We assert the root has no descendants
        // contributing — only its own RSS.
        $sampler = $this->fakeSamplerWith([
            '/proc/100/status'            => $this->statusWithRss(2048),
            '/proc/100/task/100/children' => '',
        ]);

        $samples = $sampler->sample(['lone' => 100]);

        $this->assertSame(2, $samples['lone']); // 2048/1024
    }

    /** @test */
    public function children_file_with_leading_and_trailing_whitespace_is_parsed(): void
    {
        // Kills UnwrapTrim on `trim($contents)` at line 123: without
        // trim, the surrounding whitespace would be passed to
        // preg_split, which produces a leading/trailing empty token.
        // Empty tokens are then filtered out, but mixed numeric tokens
        // around real PIDs must still parse. With trim, the PID list is
        // [200, 300]; without trim it would still be [200, 300]
        // because preg_split tolerates surrounding whitespace, but if
        // the children file has only whitespace, trim makes it the empty
        // string and triggers the early-return at line 119; without trim
        // the regex engine would split into a single empty token and
        // proceed (no descendants). The mutant becomes detectable when
        // the trimmed-empty content is the ONLY signal of "no children".
        $sampler = $this->fakeSamplerWith([
            '/proc/100/status'            => $this->statusWithRss(1024),
            '/proc/100/task/100/children' => "   \n  \t  ",
            // No /proc/<other>/... entries: if the mutant proceeds and
            // somehow yields phantom child PIDs, the sample would still
            // produce 1 because no real status backs them. The point of
            // this test is to pin the documented "whitespace-only
            // children file == no descendants" contract.
        ]);

        $samples = $sampler->sample(['solo' => 100]);

        $this->assertSame(1, $samples['solo']); // root only, 1024 kB
    }

    /** @test */
    public function visited_set_prevents_revisiting_already_seen_descendants(): void
    {
        // Kills Continue_ on the visited-skip at line 73 and TrueValue
        // on the marker assignment at line 75. Diamond shape: root
        // 100 has two children 200 and 250, both pointing to 300 (the
        // grandchild). Without the visited skip, 300 would be summed
        // twice.
        $sampler = $this->fakeSamplerWith([
            '/proc/100/status'            => $this->statusWithRss(1024),
            '/proc/100/task/100/children' => '200 250',
            '/proc/200/status'            => $this->statusWithRss(2048),
            '/proc/200/task/200/children' => '300',
            '/proc/250/status'            => $this->statusWithRss(2048),
            '/proc/250/task/250/children' => '300',
            '/proc/300/status'            => $this->statusWithRss(4096),
            '/proc/300/task/300/children' => '',
        ]);

        $samples = $sampler->sample(['diamond' => 100]);

        // (1024 + 2048 + 2048 + 4096) / 1024 = 9. Without visited check
        // 300 (4 MB) would be counted twice -> 13.
        $this->assertSame(9, $samples['diamond']);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * @param array<string, string> $procMap path => synthetic content
     */
    private function fakeSamplerWith(array $procMap): LinuxRssSampler
    {
        return new class ($procMap) extends LinuxRssSampler {
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

    private function statusWithRss(int $kb): string
    {
        return "Name:\ttest\nVmRSS:\t   {$kb} kB\n";
    }

    private function skipUnlessLinuxProc(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_dir('/proc')) {
            $this->markTestSkipped('LinuxRssSampler requires Linux with /proc mounted');
        }
    }
}
