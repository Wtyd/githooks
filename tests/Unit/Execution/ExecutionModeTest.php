<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\ExecutionMode;

class ExecutionModeTest extends TestCase
{
    /** @test */
    public function full_is_valid()
    {
        $this->assertTrue(ExecutionMode::isValid('full'));
    }

    /** @test */
    public function fast_is_valid()
    {
        $this->assertTrue(ExecutionMode::isValid('fast'));
    }

    /** @test */
    public function fast_branch_is_valid()
    {
        $this->assertTrue(ExecutionMode::isValid('fast-branch'));
    }

    /**
     * @test
     * @dataProvider invalidModeProvider
     */
    public function invalid_modes_are_rejected(string $mode)
    {
        $this->assertFalse(ExecutionMode::isValid($mode));
    }

    public function invalidModeProvider(): array
    {
        return [
            'empty string'       => [''],
            'underscore variant' => ['fast_branch'],
            'unknown'            => ['turbo'],
            'partial'            => ['fas'],
            'uppercase'          => ['FAST'],
        ];
    }

    /** @test */
    public function all_contains_exactly_three_modes()
    {
        $this->assertCount(3, ExecutionMode::ALL);
        $this->assertContains(ExecutionMode::FULL, ExecutionMode::ALL);
        $this->assertContains(ExecutionMode::FAST, ExecutionMode::ALL);
        $this->assertContains(ExecutionMode::FAST_BRANCH, ExecutionMode::ALL);
    }

    /** @test */
    public function constants_have_expected_values()
    {
        $this->assertEquals('full', ExecutionMode::FULL);
        $this->assertEquals('fast', ExecutionMode::FAST);
        $this->assertEquals('fast-branch', ExecutionMode::FAST_BRANCH);
    }
}
