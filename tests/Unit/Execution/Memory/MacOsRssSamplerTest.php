<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Memory;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\Memory\MacOsRssSampler;

/**
 * Parser-only tests for MacOsRssSampler. They drive the sampler through
 * a subclass that bypasses the real `ps` invocation, so they run on any
 * platform. The integration test that actually shells out to `ps` lives
 * in MacOsRssSamplerIntegrationTest with @group macos.
 */
class MacOsRssSamplerTest extends TestCase
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
