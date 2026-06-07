<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Memory;

use Tests\Utils\TestCase\UnitTestCase;
use Symfony\Component\Process\Process;
use Wtyd\GitHooks\Execution\Memory\LinuxRssSampler;

/**
 * Real /proc walk against a real subprocess tree. Mirrors the role of
 * MacOsRssSamplerIntegrationTest: verifies the production class wired
 * to the kernel actually sums RSS across descendants — the regression
 * fixed in 959422a (sampler used to read only the root PID, missing
 * the analyzer child under Symfony's shell wrapper).
 *
 * Slow by design (spawns a subprocess and waits for it to allocate),
 * so it lives behind @group integration and is excluded from the
 * default Unit run.
 *
 * @group linux
 * @group integration
 */
class LinuxRssSamplerIntegrationTest extends UnitTestCase
{
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_dir('/proc')) {
            $this->markTestSkipped('LinuxRssSampler requires Linux with /proc mounted');
        }
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
        // The shell itself is ~1-2 MB; the php child should add >= ~20 MB.
        $cmd = 'sh -c "php -r \'\\$a=str_repeat(chr(65),1024*1024*30); usleep(800000);\' & wait"';
        $proc = Process::fromShellCommandLine($cmd);
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
