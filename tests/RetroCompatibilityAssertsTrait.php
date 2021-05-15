<?php

namespace Tests;

use PHPUnit\Framework\Constraint\FileExists;
use PHPUnit\Framework\Constraint\LogicalNot;
use PHPUnit\Framework\Constraint\RegularExpression;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Runner\Version as PhpunitVersion;

/**
 * Some asserts methods are deprecated for phpunit 9.x. This trait contains the needed asserts in the GitHooks tests.
 * The methods in this trait are copy/paste from PHPUnit\Framework\Assert in the new version.
 * If phpunit version is equals or greater than 9.x only override the methods.
 * if phpunit version is minor than 9.x this trait creates the methods
 */
trait RetroCompatibilityAssertsTrait
{

    // public static function setDeprecatedAsserts(): void
    // {
    //     if (version_compare(PhpunitVersion::id(), '9.0.0', '<')) {
    //     }
    // }

    /**
     * Asserts that a string matches a given regular expression.
     *
     * @throws ExpectationFailedException
     * @throws \SebastianBergmann\RecursionContext\InvalidArgumentException
     */
    public static function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        static::assertThat($string, new RegularExpression($pattern), $message);
    }

    /**
     * Asserts that a file does not exist.
     *
     * @throws ExpectationFailedException
     * @throws \SebastianBergmann\RecursionContext\InvalidArgumentException
     */
    public static function assertFileDoesNotExist(string $filename, string $message = ''): void
    {
        static::assertThat($filename, new LogicalNot(new FileExists()), $message);
    }
}
