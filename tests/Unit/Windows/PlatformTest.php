<?php

declare(strict_types=1);

namespace Tests\Unit\Windows;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Utils\Platform;

class PlatformTest extends TestCase
{
    /** @test */
    public function is_windows_returns_bool()
    {
        $this->assertIsBool(Platform::isWindows());
    }

    /** @test */
    public function is_windows_matches_current_platform()
    {
        $expected = substr(PHP_OS, 0, 3) === 'WIN';

        $this->assertSame($expected, Platform::isWindows());
    }

    /** @test */
    public function normalize_path_converts_forward_slashes()
    {
        $result = Platform::normalizePath('src/Models/User.php');

        $this->assertSame('src' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'User.php', $result);
    }

    /** @test */
    public function normalize_path_converts_backslashes()
    {
        $result = Platform::normalizePath('src\\Models\\User.php');

        $this->assertSame('src' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'User.php', $result);
    }

    /** @test */
    public function normalize_path_handles_mixed_separators()
    {
        $result = Platform::normalizePath('src/Models\\User.php');

        $this->assertSame('src' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'User.php', $result);
    }

    /** @test */
    public function normalize_path_preserves_already_normalized()
    {
        $path = 'src' . DIRECTORY_SEPARATOR . 'User.php';
        $this->assertSame($path, Platform::normalizePath($path));
    }

    /** @test */
    public function stderr_redirect_returns_valid_string()
    {
        $redirect = Platform::stderrRedirect();

        if (Platform::isWindows()) {
            $this->assertSame('2>NUL', $redirect);
        } else {
            $this->assertSame('2>/dev/null', $redirect);
        }
    }
}
