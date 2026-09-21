<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Process;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Execution\Process\LinuxProcessTree;
use Wtyd\GitHooks\Execution\Process\MacOsProcessTree;
use Wtyd\GitHooks\Execution\Process\NullProcessTree;
use Wtyd\GitHooks\Execution\Process\ProcessTreeFactory;

class ProcessTreeFactoryTest extends UnitTestCase
{
    /** @test */
    public function it_returns_the_linux_tree_when_platform_is_linux_with_proc(): void
    {
        $tree = (new ProcessTreeFactory('Linux', true))->create();

        $this->assertInstanceOf(LinuxProcessTree::class, $tree);
        $this->assertTrue($tree->isAvailable());
    }

    /** @test */
    public function it_returns_the_macos_tree_on_darwin(): void
    {
        $tree = (new ProcessTreeFactory('Darwin', false))->create();

        $this->assertInstanceOf(MacOsProcessTree::class, $tree);
        $this->assertTrue($tree->isAvailable());
    }

    /** @test */
    public function it_returns_the_null_tree_with_the_platform_in_the_reason_on_windows(): void
    {
        $tree = (new ProcessTreeFactory('Windows', false))->create();

        $this->assertInstanceOf(NullProcessTree::class, $tree);
        $this->assertSame('process tree not available on Windows', $tree->getUnavailableReason());
    }

    /** @test */
    public function it_returns_the_null_tree_when_linux_lacks_proc(): void
    {
        $tree = (new ProcessTreeFactory('Linux', false))->create();

        $this->assertInstanceOf(NullProcessTree::class, $tree);
        $this->assertSame('process tree not available: /proc not mounted', $tree->getUnavailableReason());
    }
}
