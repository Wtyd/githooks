<?php

declare(strict_types=1);

namespace Tests\Unit\Execution\Memory;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\Memory\LinuxRssSampler;
use Wtyd\GitHooks\Execution\Memory\MacOsRssSampler;
use Wtyd\GitHooks\Execution\Memory\MemorySamplerFactory;
use Wtyd\GitHooks\Execution\Memory\NullRssSampler;

class MemorySamplerFactoryTest extends TestCase
{
    /** @test */
    public function it_returns_linux_sampler_when_platform_is_linux_with_proc(): void
    {
        $factory = new MemorySamplerFactory('Linux', true);

        $sampler = $factory->create();

        $this->assertInstanceOf(LinuxRssSampler::class, $sampler);
        $this->assertTrue($sampler->isAvailable());
    }

    /** @test */
    public function it_returns_macos_sampler_on_darwin(): void
    {
        $factory = new MemorySamplerFactory('Darwin', false);

        $sampler = $factory->create();

        $this->assertInstanceOf(MacOsRssSampler::class, $sampler);
        $this->assertTrue($sampler->isAvailable());
    }

    /** @test */
    public function it_returns_null_sampler_with_short_reason_on_windows(): void
    {
        $factory = new MemorySamplerFactory('Windows', false);

        $sampler = $factory->create();

        $this->assertInstanceOf(NullRssSampler::class, $sampler);
        $this->assertSame('RSS sampling not available on Windows', $sampler->getUnavailableReason());
    }

    /** @test */
    public function it_returns_null_sampler_when_linux_lacks_proc(): void
    {
        $factory = new MemorySamplerFactory('Linux', false);

        $sampler = $factory->create();

        $this->assertInstanceOf(NullRssSampler::class, $sampler);
        $this->assertSame('RSS sampling not available: /proc not mounted', $sampler->getUnavailableReason());
    }
}
