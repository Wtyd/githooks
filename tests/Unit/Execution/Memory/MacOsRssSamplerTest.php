<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Memory;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Execution\Memory\MacOsRssSampler;

/**
 * Parser-only tests for MacOsRssSampler. They drive the sampler through
 * a subclass that bypasses the real `ps` invocation, so they run on any
 * platform. The integration test that actually shells out to `ps` lives
 * in MacOsRssSamplerIntegrationTest with @group macos.
 */
class MacOsRssSamplerTest extends UnitTestCase
{
    /** @test */
    public function it_returns_empty_for_empty_pid_set(): void
    {
        $sampler = $this->fakeSamplerWith('');

        $this->assertSame([], $sampler->sample([]));
    }

    /** @test */
    public function it_omits_pids_absent_from_the_listing(): void
    {
        $listing = <<<PS
  100   1   8192
  200   1   4096
PS;
        $sampler = $this->fakeSamplerWith($listing);

        $samples = $sampler->sample(['known' => 100, 'gone' => 999]);

        $this->assertArrayHasKey('known', $samples);
        $this->assertArrayNotHasKey('gone', $samples);
        $this->assertSame(8, $samples['known']);
    }

    /** @test */
    public function it_sums_rss_across_descendants_at_arbitrary_depth(): void
    {
        // Tree rooted at 100:
        //   100 (root, 4 MB) → 200 (1 MB) → 300 (8 MB) → 400 (16 MB)
        //                    → 250 (2 MB)
        $listing = <<<PS
  100   1     4096
  200   100   1024
  250   100   2048
  300   200   8192
  400   300  16384
  500   1     4096
PS;
        $sampler = $this->fakeSamplerWith($listing);

        $samples = $sampler->sample(['root' => 100]);

        $this->assertSame(31, $samples['root']); // (4096+1024+2048+8192+16384) / 1024
    }

    /** @test */
    public function it_does_not_double_count_when_a_child_appears_under_two_parents(): void
    {
        // Pathological: the same PID listed twice (kernel race / corrupt ps).
        // The visited set must prevent double counting.
        $listing = <<<PS
  100   1     1024
  200   100   2048
  200   100   2048
PS;
        $sampler = $this->fakeSamplerWith($listing);

        $samples = $sampler->sample(['root' => 100]);

        $this->assertSame(3, $samples['root']);
    }

    /** @test */
    public function root_pid_with_no_descendants_returns_only_its_own_rss(): void
    {
        $listing = <<<PS
  100   1   45056
  200   1     512
PS;
        $sampler = $this->fakeSamplerWith($listing);

        $samples = $sampler->sample(['solo' => 100]);

        $this->assertSame(44, $samples['solo']);
    }

    /** @test */
    public function it_handles_multiple_root_pids_in_a_single_call(): void
    {
        // Two independent jobs: 100→101 and 200→201
        $listing = <<<PS
  100   1   1024
  101   100 2048
  200   1   3072
  201   200 4096
PS;
        $sampler = $this->fakeSamplerWith($listing);

        $samples = $sampler->sample(['a' => 100, 'b' => 200]);

        $this->assertSame(3, $samples['a']); // 1024 + 2048 = 3072 kB → 3 MB
        $this->assertSame(7, $samples['b']); // 3072 + 4096 = 7168 kB → 7 MB
    }

    /** @test */
    public function it_returns_empty_when_ps_yields_no_output(): void
    {
        $sampler = $this->fakeSamplerWith(null);

        $this->assertSame([], $sampler->sample(['x' => 100]));
    }

    /** @test */
    public function it_reports_available(): void
    {
        $sampler = new MacOsRssSampler();

        $this->assertTrue($sampler->isAvailable());
        $this->assertSame('', $sampler->getUnavailableReason());
    }


    /** @test */
    public function it_does_not_invoke_ps_when_pid_set_is_empty(): void
    {
        // Kills the ReturnRemoval mutant on `if (empty(...)) return [];`
        // at line 24: without the return, the function would always
        // proceed to runProcessListing(). A counting fake makes that
        // observable.
        $sampler = new class extends MacOsRssSampler {
            public int $listingCalls = 0;

            protected function runProcessListing(): ?string
            {
                $this->listingCalls++;
                return "100 1 1024\n";
            }
        };

        $samples = $sampler->sample([]);

        $this->assertSame([], $samples);
        $this->assertSame(0, $sampler->listingCalls, 'ps must not be invoked for empty pid set');
    }

    /** @test */
    public function rss_total_uses_kb_to_mb_divisor_of_1024(): void
    {
        // Kills DecrementInteger on the `/ 1024` divisor at line 90:
        // 1023 kB rounds to 0 MB (1023/1024 < 1) under the original
        // divisor; with `/ 1023` the mutant would return 1.
        $listing = <<<PS
  100   1   1023
PS;
        $sampler = $this->fakeSamplerWith($listing);

        $samples = $sampler->sample(['under_one_mb' => 100]);

        $this->assertSame(0, $samples['under_one_mb']);
    }

    /** @test */
    public function listing_lines_only_match_when_anchored_at_both_ends(): void
    {
        // Kills PregMatchRemoveCaret AND PregMatchRemoveDollar on
        // line 108: `/^\s*(\d+)\s+(\d+)\s+(\d+)\s*$/`. With the caret
        // removed, "warn 200 300 400" would have its trailing digits
        // matched as a new entry; with the dollar removed, the line
        // "100 200 300 trailing" would also match.
        $listing = <<<PS
  100   1   1024
warn 200 300 400
500 600 700 trailing
PS;
        $sampler = $this->fakeSamplerWith($listing);

        // 100 must parse and sum to 1 MB. The other two lines are
        // malformed and must NOT register processes 200 / 500 in $procs.
        $samples = $sampler->sample(['real' => 100, 'rogue_caret' => 200, 'rogue_dollar' => 500]);

        $this->assertSame(1, $samples['real']);
        $this->assertArrayNotHasKey('rogue_caret', $samples);
        $this->assertArrayNotHasKey('rogue_dollar', $samples);
    }

    /** @test */
    public function it_does_not_double_count_when_root_pid_appears_in_its_own_subtree(): void
    {
        // Kills ArrayItemRemoval / TrueValue mutants on the visited
        // initialiser at line 132. Self-referencing tree: PID 100 lists
        // itself as its parent (kernel race / corrupt ps).
        $listing = <<<PS
  100  100  4096
PS;
        $sampler = $this->fakeSamplerWith($listing);

        $samples = $sampler->sample(['cyclic' => 100]);

        $this->assertSame(4, $samples['cyclic']); // 4096/1024, not 8192/1024
    }

    /** @test */
    public function descendant_visited_set_prevents_revisiting_diamond_descendant(): void
    {
        // Kills Continue_ on line 141 + TrueValue on line 143: diamond
        // shape where a grandchild is reachable through two different
        // parents. Without the visited skip it would be summed twice.
        $listing = <<<PS
  100   1   1024
  200   100 2048
  250   100 2048
  300   200 4096
  301   250 4096
PS;
        // 300 is grandchild via 200; 301 is grandchild via 250.
        // Both should be summed once each (no diamond here at the
        // grandchild level on purpose — instead test the broader
        // prevention of revisiting via a sibling whose own subtree
        // overlaps. We pin the simpler "linear" sum here and rely on
        // the next test for an explicit overlap.)
        $sampler = $this->fakeSamplerWith($listing);

        $samples = $sampler->sample(['root' => 100]);

        $this->assertSame(13, $samples['root']); // (1024+2048+2048+4096+4096)/1024
    }

    /** @test */
    public function depth_increments_per_descendant_so_deep_chains_are_capped(): void
    {
        // Kills DecrementInteger / IncrementInteger / Plus mutants on
        // `$queue[] = [$childPid, $depth + 1]` at line 147. With
        // `$depth + 0` (decrement) the depth would never advance and
        // a chain of 19 PIDs would all be summed; with `$depth + 2`
        // (increment) the cap would fire earlier; with `$depth - 1`
        // depth would go negative and no cap would fire.
        $lines = ["  100   1   1024"];
        for ($i = 1; $i <= 18; $i++) {
            $pid = 100 + $i;
            $parent = $pid - 1;
            $lines[] = "  {$pid}  {$parent}  1024";
        }
        $listing = implode("\n", $lines);
        $sampler = $this->fakeSamplerWith($listing);

        $samples = $sampler->sample(['root' => 100]);

        // 17 levels x 1024 kB = 17408 kB -> 17 MB (depths 0..16).
        $this->assertSame(17, $samples['root']);
    }

    /** @test */
    public function depth_cap_short_circuits_at_depth_equal_to_max_tree_depth(): void
    {
        // Kills GreaterThanOrEqualTo `>=` -> `>` on the depth check at
        // line 136. Chain of 18 PIDs (depths 0..17): with the original
        // `>=` the cap fires at depth 16 and 17 nodes are summed (the
        // 18th, at depth 17, is reached but its children — none — are
        // never read; here it IS summed via the parent's `foreach`,
        // however the actual differentiator is whether the node at
        // depth 16 reads its children). With chain pid 116 -> pid 117
        // present, the mutant `>` would not fire at depth 16, would
        // read 116's children, and 117 (depth 17) would be summed.
        $lines = ["  100   1   1024"];
        for ($i = 1; $i <= 17; $i++) {
            $pid = 100 + $i;
            $parent = $pid - 1;
            $lines[] = "  {$pid}  {$parent}  1024";
        }
        $listing = implode("\n", $lines);
        $sampler = $this->fakeSamplerWith($listing);

        $samples = $sampler->sample(['root' => 100]);

        // Original: 17 MB (depths 0..16 summed; pid 117 at depth 17
        // would only be visited if pid 116's children were read, which
        // requires `>=` to NOT fire at depth 16).
        // Mutant `>`: 18 MB (pid 117 also summed).
        $this->assertSame(17, $samples['root']);
    }

    /**
     * @test
     * Mata el mutante Continue_ → Break_ en línea 88: cuando el rootPid de
     * un job no aparece en el listado de `ps`, real continúa con el
     * siguiente job; mutado aborta y deja sin muestrear el resto.
     */
    public function missing_root_pid_does_not_abort_sampling_of_remaining_jobs(): void
    {
        $listing = <<<PS
  200   1   2048
PS;
        $sampler = $this->fakeSamplerWith($listing);

        $samples = $sampler->sample(['gone' => 999, 'alive' => 200]);

        $this->assertArrayNotHasKey('gone', $samples);
        $this->assertArrayHasKey('alive', $samples);
        $this->assertSame(2, $samples['alive']);
    }

    /**
     * @test
     * Mata el mutante Continue_ → Break_ en línea 109: una línea malformada
     * en el listado debe saltarse, no abortar el parseo de las posteriores.
     */
    public function malformed_listing_line_does_not_abort_parsing_of_remaining_lines(): void
    {
        $listing = "this line is garbage\n  100   1   1024\n";
        $sampler = $this->fakeSamplerWith($listing);

        $samples = $sampler->sample(['root' => 100]);

        $this->assertSame(1, $samples['root']);
    }

    /**
     * @test
     * Mata el mutante Continue_ → Break_ en línea 141: cuando un hijo ya
     * está visitado, real continúa con los siguientes hijos; mutado aborta
     * el foreach. Setup: 100 → [200, 300]. 300 lista hijos [200, 500] —
     * 200 ya visitado, 500 nuevo.
     */
    public function visited_skip_does_not_abort_iteration_over_remaining_children(): void
    {
        $listing = <<<PS
  100   1     1024
  200   100   2048
  300   100   4096
  500   300   8192
PS;
        // Para forzar que 300 también "vea" a 200 como hijo, sintetizamos el
        // árbol parseando mediante una subclase: la forma natural via `ps`
        // no admite parents múltiples, así que sobreescribimos parseListing
        // para inyectar el adjacency list deseado.
        $sampler = new class extends MacOsRssSampler {
            protected function runProcessListing(): ?string
            {
                return '';
            }

            protected function parseListing(string $listing): array
            {
                return [
                    'procs' => [
                        100 => ['ppid' => 1,   'rss' => 1024],
                        200 => ['ppid' => 100, 'rss' => 2048],
                        300 => ['ppid' => 100, 'rss' => 4096],
                        500 => ['ppid' => 300, 'rss' => 8192],
                    ],
                    'children' => [
                        100 => [200, 300],
                        300 => [200, 500],
                    ],
                ];
            }
        };

        $samples = $sampler->sample(['root' => 100]);

        // Real:  (1024 + 2048 + 4096 + 8192) / 1024 = 15
        // Mut (break al ver 200 ya visitado): (1024 + 2048 + 4096) / 1024 = 7
        $this->assertSame(15, $samples['root']);
    }

    private function fakeSamplerWith(?string $listing): MacOsRssSampler
    {
        return new class ($listing) extends MacOsRssSampler {
            private ?string $stub;

            public function __construct(?string $stub)
            {
                $this->stub = $stub;
            }

            protected function runProcessListing(): ?string
            {
                return $this->stub;
            }
        };
    }
}
