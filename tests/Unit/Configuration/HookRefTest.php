<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\HookRef;
use Wtyd\GitHooks\Configuration\ValidationResult;

class HookRefTest extends TestCase
{
    // ========================================================================
    // fromString
    // ========================================================================

    /** @test */
    public function fromString_creates_ref_with_empty_conditions()
    {
        $ref = HookRef::fromString('my_flow');

        $this->assertSame('my_flow', $ref->getTarget());
        $this->assertEmpty($ref->getOnlyOnBranches());
        $this->assertEmpty($ref->getExcludeOnBranches());
        $this->assertEmpty($ref->getOnlyFiles());
        $this->assertEmpty($ref->getExcludeFiles());
        $this->assertNull($ref->getExecution());
        $this->assertFalse($ref->hasConditions());
    }

    // ========================================================================
    // fromArray: happy paths
    // ========================================================================

    /** @test */
    public function fromArray_with_flow_key_creates_ref()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['flow' => 'qa'], $result);

        $this->assertNotNull($ref);
        $this->assertSame('qa', $ref->getTarget());
        $this->assertFalse($result->hasErrors());
    }

    /** @test */
    public function fromArray_with_job_key_creates_ref()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['job' => 'phpstan_src'], $result);

        $this->assertNotNull($ref);
        $this->assertSame('phpstan_src', $ref->getTarget());
    }

    /** @test */
    public function fromArray_flow_takes_precedence_over_job()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['flow' => 'qa', 'job' => 'phpcs'], $result);

        $this->assertNotNull($ref);
        $this->assertSame('qa', $ref->getTarget());
    }

    // ========================================================================
    // fromArray: validation errors
    // ========================================================================

    /** @test */
    public function fromArray_without_flow_or_job_returns_null_with_error()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['only-on' => 'main'], $result);

        $this->assertNull($ref);
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function fromArray_with_non_string_target_returns_null_with_error()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['flow' => 123], $result);

        $this->assertNull($ref);
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function fromArray_with_null_target_returns_null_with_error()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['flow' => null], $result);

        $this->assertNull($ref);
        $this->assertTrue($result->hasErrors());
    }

    // ========================================================================
    // fromArray: condition normalization (string → array)
    // ========================================================================

    /** @test */
    public function fromArray_normalizes_only_on_string_to_array()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['flow' => 'qa', 'only-on' => 'main'], $result);

        $this->assertNotNull($ref);
        $this->assertSame(['main'], $ref->getOnlyOnBranches());
    }

    /** @test */
    public function fromArray_normalizes_exclude_on_string_to_array()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['flow' => 'qa', 'exclude-on' => 'develop'], $result);

        $this->assertNotNull($ref);
        $this->assertSame(['develop'], $ref->getExcludeOnBranches());
    }

    /** @test */
    public function fromArray_normalizes_only_files_string_to_array()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['flow' => 'qa', 'only-files' => 'src/*.php'], $result);

        $this->assertNotNull($ref);
        $this->assertSame(['src/*.php'], $ref->getOnlyFiles());
    }

    /** @test */
    public function fromArray_normalizes_exclude_files_string_to_array()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['flow' => 'qa', 'exclude-files' => 'vendor/**'], $result);

        $this->assertNotNull($ref);
        $this->assertSame(['vendor/**'], $ref->getExcludeFiles());
    }

    /** @test */
    public function fromArray_keeps_arrays_as_is()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray([
            'flow' => 'qa',
            'only-on' => ['main', 'develop'],
            'only-files' => ['src/*.php', 'app/*.php'],
        ], $result);

        $this->assertNotNull($ref);
        $this->assertSame(['main', 'develop'], $ref->getOnlyOnBranches());
        $this->assertSame(['src/*.php', 'app/*.php'], $ref->getOnlyFiles());
    }

    // ========================================================================
    // fromArray: execution validation
    // ========================================================================

    /** @test */
    public function fromArray_with_valid_execution_sets_it()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['flow' => 'qa', 'execution' => 'fast'], $result);

        $this->assertNotNull($ref);
        $this->assertSame('fast', $ref->getExecution());
        $this->assertFalse($result->hasErrors());
    }

    /** @test */
    public function fromArray_with_invalid_execution_returns_null_with_error()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['flow' => 'qa', 'execution' => 'turbo'], $result);

        $this->assertNull($ref);
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function fromArray_with_non_string_execution_returns_null_with_error()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['flow' => 'qa', 'execution' => true], $result);

        $this->assertNull($ref);
        $this->assertTrue($result->hasErrors());
    }

    // ========================================================================
    // fromArray: unknown keys → warnings
    // ========================================================================

    /** @test */
    public function fromArray_with_unknown_key_adds_warning()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray(['flow' => 'qa', 'unknown-key' => 'value'], $result);

        $this->assertNotNull($ref);
        $this->assertFalse($result->hasErrors());
        $this->assertNotEmpty($result->getWarnings());
        $this->assertStringContainsString('unknown-key', $result->getWarnings()[0]);
    }

    /** @test */
    public function fromArray_with_all_known_keys_has_no_warnings()
    {
        $result = new ValidationResult();
        $ref = HookRef::fromArray([
            'flow' => 'qa',
            'only-on' => ['main'],
            'exclude-on' => ['develop'],
            'only-files' => ['src/*.php'],
            'exclude-files' => ['vendor/**'],
            'execution' => 'full',
        ], $result);

        $this->assertNotNull($ref);
        $this->assertEmpty($result->getWarnings());
    }

    // ========================================================================
    // hasConditions
    // ========================================================================

    /** @test */
    public function hasConditions_true_with_only_on()
    {
        $ref = new HookRef('qa', ['main']);
        $this->assertTrue($ref->hasConditions());
    }

    /** @test */
    public function hasConditions_true_with_exclude_on()
    {
        $ref = new HookRef('qa', [], [], [], ['develop']);
        $this->assertTrue($ref->hasConditions());
    }

    /** @test */
    public function hasConditions_true_with_only_files()
    {
        $ref = new HookRef('qa', [], ['src/*.php']);
        $this->assertTrue($ref->hasConditions());
    }

    /** @test */
    public function hasConditions_true_with_exclude_files()
    {
        $ref = new HookRef('qa', [], [], ['vendor/**']);
        $this->assertTrue($ref->hasConditions());
    }

    /** @test */
    public function hasConditions_false_when_all_empty()
    {
        $ref = new HookRef('qa');
        $this->assertFalse($ref->hasConditions());
    }
}
