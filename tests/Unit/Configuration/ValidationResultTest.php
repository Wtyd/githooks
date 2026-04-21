<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ValidationResult;

class ValidationResultTest extends TestCase
{
    /** @test */
    public function it_starts_empty()
    {
        $result = new ValidationResult();

        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->getErrors());
        $this->assertEmpty($result->getWarnings());
    }

    /** @test */
    public function it_collects_errors()
    {
        $result = new ValidationResult();
        $result->addError('first error');
        $result->addError('second error');

        $this->assertTrue($result->hasErrors());
        $this->assertCount(2, $result->getErrors());
        $this->assertEquals('first error', $result->getErrors()[0]);
    }

    /** @test */
    public function it_collects_warnings()
    {
        $result = new ValidationResult();
        $result->addWarning('a warning');

        $this->assertFalse($result->hasErrors());
        $this->assertCount(1, $result->getWarnings());
    }

    /**
     * @test
     * Kills L46 UnwrapArrayMerge: `array_merge($this->warnings, $other->warnings)`
     * collapsed to one side would drop the counterpart's warnings. Both sides
     * must carry warnings and the assert must cover content + order.
     */
    public function it_merges_errors_and_warnings_from_both_sides()
    {
        $a = new ValidationResult();
        $a->addError('error A');
        $a->addWarning('warning A');

        $b = new ValidationResult();
        $b->addError('error B');
        $b->addWarning('warning B');

        $merged = $a->merge($b);

        $this->assertSame(['error A', 'error B'], $merged->getErrors());
        $this->assertSame(['warning A', 'warning B'], $merged->getWarnings());
    }

    /** @test */
    public function merge_does_not_mutate_operands()
    {
        $a = new ValidationResult();
        $a->addError('error A');

        $b = new ValidationResult();
        $b->addWarning('warning B');

        $a->merge($b);

        $this->assertSame(['error A'], $a->getErrors());
        $this->assertSame([], $a->getWarnings());
        $this->assertSame([], $b->getErrors());
        $this->assertSame(['warning B'], $b->getWarnings());
    }
}
