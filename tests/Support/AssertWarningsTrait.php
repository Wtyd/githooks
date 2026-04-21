<?php

declare(strict_types=1);

namespace Tests\Support;

use Wtyd\GitHooks\Configuration\ValidationResult;

/**
 * Helpers for asserting warning/error messages by exact equality rather than
 * substring. Exact matches are the only way to kill Infection's `Concat` and
 * `ConcatOperandRemoval` mutants, which remove half of a concatenated literal.
 */
trait AssertWarningsTrait
{
    protected function assertWarningEquals(string $expected, ValidationResult $result): void
    {
        $this->assertContains(
            $expected,
            $result->getWarnings(),
            sprintf(
                "Expected exact warning was not found.\nExpected: %s\nActual warnings:\n%s",
                $expected,
                implode("\n", array_map(function (string $w) {
                    return '  - ' . $w;
                }, $result->getWarnings()))
            )
        );
    }

    protected function assertErrorEquals(string $expected, ValidationResult $result): void
    {
        $this->assertContains(
            $expected,
            $result->getErrors(),
            sprintf(
                "Expected exact error was not found.\nExpected: %s\nActual errors:\n%s",
                $expected,
                implode("\n", array_map(function (string $e) {
                    return '  - ' . $e;
                }, $result->getErrors()))
            )
        );
    }
}
