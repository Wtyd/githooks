<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\Deprecation;
use Wtyd\GitHooks\Configuration\ValidationResult;

class ValidationResultTest extends UnitTestCase
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

    /**
     * @test
     * Maximal fixture covering all three collections on BOTH sides. Kills both
     * L67 UnwrapArrayMerge mutants on the deprecations line: collapsing it to
     * `$this->deprecations` drops `$other`'s, collapsing it to `$other->deprecations`
     * drops `$this`'s. Errors and warnings are asserted too so a single test
     * guards the whole merge contract (content + order + count).
     */
    public function it_merges_deprecations_from_both_sides_alongside_errors_and_warnings()
    {
        $depA = new Deprecation('jobA', 'old-a', 'new-a');
        $depB = new Deprecation('jobB', 'old-b', 'new-b');

        $a = new ValidationResult();
        $a->addError('error A');
        $a->addWarning('warning A');
        $a->addDeprecation($depA);

        $b = new ValidationResult();
        $b->addError('error B');
        $b->addWarning('warning B');
        $b->addDeprecation($depB);

        $merged = $a->merge($b);

        $this->assertSame(['error A', 'error B'], $merged->getErrors());
        // addDeprecation also surfaces the canonical warning message, so each
        // side contributes its own warning followed by its deprecation message.
        $this->assertSame(
            ['warning A', $depA->getWarningMessage(), 'warning B', $depB->getWarningMessage()],
            $merged->getWarnings()
        );
        $this->assertSame([$depA, $depB], $merged->getDeprecations());
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
