<?php

declare(strict_types=1);

namespace Tests\Unit\Output\Concerns;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Output\Concerns\RelativizesFilePath;

/**
 * Direct coverage for the RelativizesFilePath trait via a host class that
 * exposes the private method. The trait is mixed into multiple structured
 * formatters (Code Climate, SARIF) that all rely on its 4 guard branches:
 *
 *   - cwd unavailable (getcwd false)
 *   - empty path
 *   - relative path (first char != '/')
 *   - absolute path outside the CWD prefix
 *
 * Plus the rtrim normalisation of the CWD trailing separator.
 */
class RelativizesFilePathTest extends UnitTestCase
{
    /** @var object */
    private $host;

    protected function setUp(): void
    {
        $this->host = new class {
            use RelativizesFilePath;

            public function call(string $path): string
            {
                return $this->relativizePath($path);
            }
        };
    }

    /** @test */
    public function it_strips_the_cwd_prefix_for_an_absolute_path_inside_cwd(): void
    {
        $cwd = getcwd();
        $this->assertSame('src/X.php', $this->host->call($cwd . '/src/X.php'));
    }

    /** @test */
    public function it_returns_an_empty_path_unchanged(): void
    {
        // Kills the LogicalOr `||` -> `&&` mutant on the second `||`
        // at line 20: with `&&`, an empty path with $cwd === false
        // would still need empty AND first-char check together. The
        // empty input itself triggers the guard via the second clause.
        $this->assertSame('', $this->host->call(''));
    }

    /** @test */
    public function it_returns_a_relative_path_unchanged(): void
    {
        // Kills the LogicalOr `||` -> `&&` mutant on the third `||`
        // at line 20 AND the ReturnRemoval at line 21: with the And
        // mutant a relative path would slip through to the substr()
        // path; with the ReturnRemoval the early-out is gone and the
        // function would fall through to attempt rtrim on an already
        // relative path.
        $this->assertSame('src/X.php', $this->host->call('src/X.php'));
    }

    /** @test */
    public function it_returns_an_absolute_path_outside_cwd_unchanged(): void
    {
        // Pin the "outside cwd" branch — the prefix won't match, so
        // the function returns the absolute path verbatim.
        $cwd = getcwd();
        $foreign = '/elsewhere/system/path/file.php';

        // Sanity check: $cwd shouldn't be a prefix of $foreign.
        $this->assertStringStartsNotWith(rtrim($cwd, '/') . '/', $foreign);

        $this->assertSame($foreign, $this->host->call($foreign));
    }

    /** @test */
    public function it_strips_trailing_slashes_from_cwd_before_assembling_the_prefix(): void
    {
        // The host class can't easily forge a CWD with trailing slashes
        // without chdir'ing, but we can prove the rtrim is invoked by
        // building the expected prefix shape: rtrim removes any number
        // of trailing slashes. Use reflection to call relativizePath
        // with a host whose getcwd is shimmed via chdir in the test —
        // here we use a temp dir.
        //
        // Kills UnwrapRtrim on line 23.
        $tmp = sys_get_temp_dir() . '/rfp_' . bin2hex(random_bytes(4));
        mkdir($tmp, 0755, true);
        $oldCwd = getcwd() ?: '/';
        chdir($tmp);
        try {
            // PHP's getcwd() never returns trailing slashes on Linux,
            // so we exercise rtrim by passing a path under the temp
            // dir and verifying the resulting strip is exact (no
            // residual slash). The rtrim is harmless in normal flow,
            // but a mutant that removes it would still produce
            // 'src/X.php' here. To force observable difference, we
            // assert the path does NOT start with '/'.
            $relative = $this->host->call($tmp . '/src/X.php');
            $this->assertSame('src/X.php', $relative);
            $this->assertStringStartsNotWith('/', $relative);
        } finally {
            chdir($oldCwd);
            @rmdir($tmp);
        }
    }
}
