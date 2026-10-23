<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

/**
 * The name the Composer scripts of 3.x projects call:
 *
 *     "post-update-cmd": "Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions"
 *
 * Kept so those projects keep installing after upgrading to 4.0, where the
 * class lives in the Wtyd\Alfil namespace. Removed in 5.0.
 *
 * @deprecated since 4.0, use \Wtyd\Alfil\Utils\ComposerUpdater
 */
class ComposerUpdater extends \Wtyd\Alfil\Utils\ComposerUpdater
{
}
