<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Utils\Platform;

class PlatformTest extends UnitTestCase
{
    /**
     * @test
     * @dataProvider paths
     * BUG-31: decides whether a baked hook `--config` is portable (relative) or
     * not (absolute), on POSIX and Windows spellings alike.
     */
    public function is_absolute_path_recognises_posix_and_windows_roots(string $path, bool $expected)
    {
        $this->assertSame($expected, Platform::isAbsolutePath($path));
    }

    /** @return array<string, array{string, bool}> */
    public function paths(): array
    {
        return [
            'posix absolute'               => ['/var/www/githooks.php', true],
            'posix root only'              => ['/', true],
            'windows drive, backslash'     => ['C:\\repo\\githooks.php', true],
            'windows drive, forward slash' => ['c:/repo/githooks.php', true],
            'relative'                     => ['qa/githooks.php', false],
            'relative with dot'            => ['./githooks.php', false],
            'parent-relative'              => ['../githooks.php', false],
            'drive letter without slash'   => ['C:githooks.php', false],
            'empty'                        => ['', false],
        ];
    }
}
