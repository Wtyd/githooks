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

    /** @test */
    public function it_merges_two_results()
    {
        $a = new ValidationResult();
        $a->addError('error A');
        $a->addWarning('warning A');

        $b = new ValidationResult();
        $b->addError('error B');

        $merged = $a->merge($b);

        $this->assertCount(2, $merged->getErrors());
        $this->assertCount(1, $merged->getWarnings());
    }
}
