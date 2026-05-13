<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssertWarningsTrait;
use Wtyd\GitHooks\Configuration\JobRef;
use Wtyd\GitHooks\Configuration\ValidationResult;

/**
 * FEAT-1 · only-files / exclude-files declarables per flow entry.
 *
 * Covers Group A of the factor table: parser × entry shape × value of each rule.
 * The override semantics (Group D) live in JobRefLocalOverrideTest because they
 * exercise array_replace_recursive end-to-end.
 */
class JobRefTest extends TestCase
{
    use AssertWarningsTrait;

    // ========================================================================
    // fromString — A1 retrocompat
    // ========================================================================

    /** @test */
    public function fromString_creates_ref_without_admission_rules()
    {
        $ref = JobRef::fromString('tests_a');

        $this->assertSame('tests_a', $ref->getTarget());
        $this->assertNull($ref->getOnlyFiles());
        $this->assertNull($ref->getExcludeFiles());
        $this->assertFalse($ref->hasAdmissionRules());
    }

    // ========================================================================
    // fromArray — happy paths
    // ========================================================================

    /** @test */
    public function fromArray_with_only_job_key_equals_string_form()
    {
        // A2
        $result = new ValidationResult();
        $ref = JobRef::fromArray(['job' => 'tests_a'], $result, 'qa');

        $this->assertNotNull($ref);
        $this->assertSame('tests_a', $ref->getTarget());
        $this->assertNull($ref->getOnlyFiles());
        $this->assertNull($ref->getExcludeFiles());
        $this->assertFalse($ref->hasAdmissionRules());
        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->getWarnings());
    }

    /** @test */
    public function fromArray_with_only_files_list_captures_rule()
    {
        // A3
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'only-files' => ['src/A/**', 'composer.json']],
            $result,
            'qa'
        );

        $this->assertNotNull($ref);
        $this->assertSame(['src/A/**', 'composer.json'], $ref->getOnlyFiles());
        $this->assertNull($ref->getExcludeFiles());
        $this->assertTrue($ref->hasAdmissionRules());
        $this->assertFalse($result->hasErrors());
    }

    /** @test */
    public function fromArray_with_exclude_files_list_captures_rule()
    {
        // A6
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'exclude-files' => ['vendor/**']],
            $result,
            'qa'
        );

        $this->assertNotNull($ref);
        $this->assertNull($ref->getOnlyFiles());
        $this->assertSame(['vendor/**'], $ref->getExcludeFiles());
        $this->assertTrue($ref->hasAdmissionRules());
    }

    /** @test */
    public function fromArray_normalizes_only_files_string_to_array()
    {
        // A13
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'only-files' => 'src/A/**'],
            $result,
            'qa'
        );

        $this->assertNotNull($ref);
        $this->assertSame(['src/A/**'], $ref->getOnlyFiles());
    }

    /** @test */
    public function fromArray_normalizes_exclude_files_string_to_array()
    {
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'exclude-files' => 'vendor/**'],
            $result,
            'qa'
        );

        $this->assertNotNull($ref);
        $this->assertSame(['vendor/**'], $ref->getExcludeFiles());
    }

    // ========================================================================
    // fromArray — null sentinel (anular regla heredada del compartido)
    // ========================================================================

    /** @test */
    public function fromArray_with_only_files_null_means_no_rule()
    {
        // A4 — null = "no rule" (used to cancel an inherited rule from githooks.php)
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'only-files' => null],
            $result,
            'qa'
        );

        $this->assertNotNull($ref);
        $this->assertNull($ref->getOnlyFiles());
        $this->assertFalse($ref->hasAdmissionRules());
        $this->assertFalse($result->hasErrors());
    }

    /** @test */
    public function fromArray_with_exclude_files_null_means_no_rule()
    {
        // A7
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'exclude-files' => null],
            $result,
            'qa'
        );

        $this->assertNotNull($ref);
        $this->assertNull($ref->getExcludeFiles());
        $this->assertFalse($ref->hasAdmissionRules());
    }

    // ========================================================================
    // fromArray — validation errors
    // ========================================================================

    /** @test */
    public function fromArray_with_empty_only_files_returns_error_pointing_to_null()
    {
        // A5 — exact-string assert kills Concat / ConcatOperandRemoval mutants
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'only-files' => []],
            $result,
            'qa'
        );

        $this->assertNull($ref);
        $this->assertErrorEquals(
            "Flow 'qa' job ref 'tests_a': 'only-files' must not be empty. Use null to disable an inherited rule.",
            $result
        );
    }

    /** @test */
    public function fromArray_with_empty_exclude_files_returns_error_pointing_to_null()
    {
        // A8
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'exclude-files' => []],
            $result,
            'qa'
        );

        $this->assertNull($ref);
        $this->assertErrorEquals(
            "Flow 'qa' job ref 'tests_a': 'exclude-files' must not be empty. Use null to disable an inherited rule.",
            $result
        );
    }

    /** @test */
    public function fromArray_without_job_key_returns_error()
    {
        // A9
        $result = new ValidationResult();
        $ref = JobRef::fromArray(['only-files' => ['src/A/**']], $result, 'qa');

        $this->assertNull($ref);
        $this->assertErrorEquals(
            "Flow 'qa': job ref must have a 'job' key with a string value.",
            $result
        );
    }

    /** @test */
    public function fromArray_with_non_string_job_returns_error()
    {
        $result = new ValidationResult();
        $ref = JobRef::fromArray(['job' => 123], $result, 'qa');

        $this->assertNull($ref);
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function fromArray_with_only_files_wrong_type_returns_error()
    {
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'only-files' => 42],
            $result,
            'qa'
        );

        $this->assertNull($ref);
        $this->assertErrorEquals(
            "Flow 'qa' job ref 'tests_a': 'only-files' must be a string, array of strings, or null.",
            $result
        );
    }

    /** @test */
    public function fromArray_with_exclude_files_wrong_type_returns_error()
    {
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'exclude-files' => 42],
            $result,
            'qa'
        );

        $this->assertNull($ref);
        $this->assertErrorEquals(
            "Flow 'qa' job ref 'tests_a': 'exclude-files' must be a string, array of strings, or null.",
            $result
        );
    }

    /** @test */
    public function fromArray_with_non_string_pattern_in_only_files_returns_error()
    {
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'only-files' => ['src/A/**', 123]],
            $result,
            'qa'
        );

        $this->assertNull($ref);
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function fromArray_with_empty_string_pattern_in_only_files_returns_error()
    {
        // A11 — empty glob string is meaningless
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'only-files' => ['']],
            $result,
            'qa'
        );

        $this->assertNull($ref);
        $this->assertErrorEquals(
            "Flow 'qa' job ref 'tests_a': 'only-files' contains an empty pattern.",
            $result
        );
    }

    /** @test */
    public function fromArray_with_duplicate_patterns_in_only_files_returns_error()
    {
        // A12
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'only-files' => ['src/A/**', 'src/A/**']],
            $result,
            'qa'
        );

        $this->assertNull($ref);
        $this->assertErrorEquals(
            "Flow 'qa' job ref 'tests_a': 'only-files' contains duplicate pattern 'src/A/**'.",
            $result
        );
    }

    /** @test */
    public function fromArray_with_duplicate_patterns_in_exclude_files_returns_error()
    {
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'exclude-files' => ['vendor/**', 'vendor/**']],
            $result,
            'qa'
        );

        $this->assertNull($ref);
        $this->assertErrorEquals(
            "Flow 'qa' job ref 'tests_a': 'exclude-files' contains duplicate pattern 'vendor/**'.",
            $result
        );
    }

    // ========================================================================
    // fromArray — unknown keys → warning with did-you-mean
    // ========================================================================

    /** @test */
    public function fromArray_with_unknown_key_adds_warning_with_suggestion()
    {
        // A10
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            ['job' => 'tests_a', 'onyl-files' => ['src/A/**']],
            $result,
            'qa'
        );

        $this->assertNotNull($ref);
        $this->assertFalse($result->hasErrors());
        $this->assertWarningEquals(
            "Flow 'qa' job ref 'tests_a': unknown key 'onyl-files'. Did you mean 'only-files'?",
            $result
        );
    }

    /** @test */
    public function fromArray_with_all_known_keys_has_no_warnings()
    {
        $result = new ValidationResult();
        $ref = JobRef::fromArray(
            [
                'job' => 'tests_a',
                'only-files' => ['src/A/**'],
                'exclude-files' => ['src/A/Vendor/**'],
            ],
            $result,
            'qa'
        );

        $this->assertNotNull($ref);
        $this->assertEmpty($result->getWarnings());
    }
}
