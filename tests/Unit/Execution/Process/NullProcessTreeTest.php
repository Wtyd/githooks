<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Process;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Execution\Process\NullProcessTree;

class NullProcessTreeTest extends UnitTestCase
{
    /** @test */
    public function it_sees_no_descendants_and_nobody_alive_and_reports_the_reason(): void
    {
        $tree = new NullProcessTree('process tree not available on Windows');

        $this->assertSame([], $tree->descendants(100));
        $this->assertSame([], $tree->alive([100, 200]));
        $this->assertFalse($tree->isAvailable());
        $this->assertSame('process tree not available on Windows', $tree->getUnavailableReason());
    }
}
