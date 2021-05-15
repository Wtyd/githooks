<?php

namespace Tests;

use PHPUnit\Runner\Version as PhpunitVersion;

trait AssertionCompatibilityTrait
{
    protected static $assertMatchesRegularExpression;

    protected static $assertFileDoesNotExist;

    /**
     * Some asserts methods are deprecated for phpunit 9.x. This sets the right way for call this deprecated asserts.
     *
     * @return string
     */
    public static function setDeprecatedAsserts(): void
    {
        if (version_compare(PhpunitVersion::id(), '9.0.0', '<')) {
            self::$assertMatchesRegularExpression = 'assertRegExp';
            self::$assertFileDoesNotExist = 'assertFileNotExists';
        } else {
            self::$assertMatchesRegularExpression = 'assertMatchesRegularExpression';
            self::$assertFileDoesNotExist = 'assertFileDoesNotExist';
        }
    }
}
