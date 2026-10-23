<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\Alfil\Utils\ComposerUpdater;

class ComposerUpdaterTest extends UnitTestCase
{
    /** @test */
    function pathToBuild_returns_empty_string_for_php_81_or_higher()
    {
        if (version_compare(phpversion(), '8.1.0', '<')) {
            $this->markTestSkipped('Requires PHP >= 8.1 to test this path');
        }

        $this->assertSame('', ComposerUpdater::pathToBuild());
    }

    /** @test */
    function pathToBuild_returns_php74_for_versions_between_74_and_81()
    {
        if (version_compare(phpversion(), '8.1.0', '>=') || version_compare(phpversion(), '7.4.0', '<')) {
            $this->markTestSkipped('Requires PHP >= 7.4 and < 8.1 to test this path');
        }

        $this->assertSame('php7.4', ComposerUpdater::pathToBuild());
    }

    /** @test */
    function pathToBuild_does_not_throw_for_current_php_version()
    {
        // Current PHP is always >= 7.4 in our environment, so this should not throw
        $result = ComposerUpdater::pathToBuild();

        $this->assertIsString($result);
        $this->assertContains($result, ['', 'php7.4']);
    }

    /**
     * @test
     * 3.x projects call the old name from their composer.json scripts
     * ("Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions"); Composer
     * resolves it through the autoloader, so it has to load and expose the
     * same static entry point until 5.0.
     */
    function the_3x_class_name_still_resolves_to_the_updater()
    {
        $legacy = 'Wtyd\GitHooks\Utils\ComposerUpdater';

        $this->assertTrue(class_exists($legacy));
        $this->assertTrue(is_subclass_of($legacy, ComposerUpdater::class));
        $this->assertTrue(is_callable([$legacy, 'phpOldVersions']));
        $this->assertSame(ComposerUpdater::pathToBuild(), $legacy::pathToBuild());
    }
}
