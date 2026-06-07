<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Memory;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Execution\Memory\NullRssSampler;

class NullRssSamplerTest extends UnitTestCase
{
    /** @test */
    public function it_always_returns_an_empty_sample(): void
    {
        $sampler = new NullRssSampler('platform not supported');

        $this->assertSame([], $sampler->sample(['x' => 1234]));
        $this->assertSame([], $sampler->sample([]));
    }

    /** @test */
    public function it_reports_unavailable_with_the_provided_reason(): void
    {
        $sampler = new NullRssSampler('Windows is not supported in v3.3');

        $this->assertFalse($sampler->isAvailable());
        $this->assertSame('Windows is not supported in v3.3', $sampler->getUnavailableReason());
    }
}
