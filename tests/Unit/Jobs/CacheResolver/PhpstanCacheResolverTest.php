<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\CacheResolver;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Jobs\CacheResolver\PhpstanCacheResolver;

/**
 * Covers the resolver behind PhpstanJob::getCachePaths.
 * Factor table:
 *   - file presence: missing / present
 *   - tmpDir: absent / literal / placeholder %currentWorkingDirectory% / placeholder %rootDir%
 *   - includes: 0 / 1 / N / nested / cyclic / override-by-child / override-by-last-include
 *   - placement: in-line comment / commented-out
 */
class PhpstanCacheResolverTest extends TestCase
{
    /** @var string[] */
    private array $paths = [];

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/phpstan-resolver-' . uniqid('', true);
        mkdir($this->sandbox, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
        if (is_dir($this->sandbox)) {
            $this->removeDir($this->sandbox);
        }
        parent::tearDown();
    }

    /** @test */
    public function returns_null_when_config_file_does_not_exist()
    {
        $this->assertNull(PhpstanCacheResolver::resolve($this->sandbox . '/missing.neon'));
    }

    /** @test */
    public function returns_null_when_neon_has_no_tmpdir_and_no_includes()
    {
        $path = $this->writeNeon('basic.neon', "parameters:\n    level: 8\n");

        $this->assertNull(PhpstanCacheResolver::resolve($path));
    }

    /** @test */
    public function returns_literal_tmpdir_when_declared_directly()
    {
        $path = $this->writeNeon('literal.neon', "parameters:\n    tmpDir: var/cache/phpstan\n");

        $this->assertSame('var/cache/phpstan', PhpstanCacheResolver::resolve($path));
    }

    /** @test */
    public function expands_current_working_directory_placeholder()
    {
        $path = $this->writeNeon('cwd.neon', "parameters:\n    tmpDir: %currentWorkingDirectory%/qa/luz/cache\n");

        $expected = (getcwd() ?: '.') . '/qa/luz/cache';
        $this->assertSame($expected, PhpstanCacheResolver::resolve($path));
    }

    /** @test */
    public function expands_root_dir_placeholder_to_config_directory()
    {
        $path = $this->writeNeon('root.neon', "parameters:\n    tmpDir: %rootDir%/cache\n");

        $expected = $this->sandbox . '/cache';
        $this->assertSame($expected, PhpstanCacheResolver::resolve($path));
    }

    /** @test */
    public function resolves_tmpdir_from_single_include()
    {
        $this->writeNeon('base.neon', "parameters:\n    tmpDir: %currentWorkingDirectory%/qa/luz/cache\n");
        $child = $this->writeNeon('child.neon', "includes:\n    - base.neon\nparameters:\n    level: 8\n");

        $expected = (getcwd() ?: '.') . '/qa/luz/cache';
        $this->assertSame($expected, PhpstanCacheResolver::resolve($child));
    }

    /** @test */
    public function child_tmpdir_overrides_included_base()
    {
        $this->writeNeon('base.neon', "parameters:\n    tmpDir: base/cache\n");
        $child = $this->writeNeon('child.neon', "includes:\n    - base.neon\nparameters:\n    tmpDir: child/cache\n");

        $this->assertSame('child/cache', PhpstanCacheResolver::resolve($child));
    }

    /** @test */
    public function last_sibling_include_with_tmpdir_wins()
    {
        $this->writeNeon('first.neon', "parameters:\n    tmpDir: first/cache\n");
        $this->writeNeon('second.neon', "parameters:\n    tmpDir: second/cache\n");
        $entry = $this->writeNeon('entry.neon', "includes:\n    - first.neon\n    - second.neon\n");

        $this->assertSame('second/cache', PhpstanCacheResolver::resolve($entry));
    }

    /** @test */
    public function recurses_through_nested_includes_three_levels_deep()
    {
        $this->writeNeon('deep.neon', "parameters:\n    tmpDir: deep/cache\n");
        $this->writeNeon('mid.neon', "includes:\n    - deep.neon\n");
        $entry = $this->writeNeon('top.neon', "includes:\n    - mid.neon\n");

        $this->assertSame('deep/cache', PhpstanCacheResolver::resolve($entry));
    }

    /** @test */
    public function cyclic_includes_do_not_loop_and_still_resolve_tmpdir()
    {
        $a = $this->writeNeon('a.neon', "includes:\n    - b.neon\nparameters:\n    tmpDir: a/cache\n");
        $this->writeNeon('b.neon', "includes:\n    - a.neon\n");

        $this->assertSame('a/cache', PhpstanCacheResolver::resolve($a));
    }

    /** @test */
    public function commented_tmpdir_line_is_ignored()
    {
        $path = $this->writeNeon('commented.neon', "parameters:\n    # tmpDir: ignored/cache\n    level: 8\n");

        $this->assertNull(PhpstanCacheResolver::resolve($path));
    }

    /** @test */
    public function inline_comment_after_tmpdir_is_stripped()
    {
        $path = $this->writeNeon('inline.neon', "parameters:\n    tmpDir: real/cache  # inline comment\n");

        $this->assertSame('real/cache', PhpstanCacheResolver::resolve($path));
    }

    /** @test */
    public function tmpdir_inside_services_block_is_not_picked_up()
    {
        // Adversarial: a service constructor argument named tmpDir would be a
        // false positive without context tracking. NEON tmpDir for PHPStan
        // lives only under top-level "parameters:".
        $path = $this->writeNeon('services.neon', "services:\n    -\n        class: My\\Service\n        arguments:\n            tmpDir: '/wrong'\nparameters:\n    level: 8\n");

        $this->assertNull(PhpstanCacheResolver::resolve($path));
    }

    /** @test */
    public function tmpdir_is_only_picked_when_under_top_level_parameters()
    {
        // Same factor: tmpDir under nested or non-parameters key is ignored.
        $path = $this->writeNeon(
            'nested.neon',
            "phpstan:\n    parameters:\n        tmpDir: nested\nparameters:\n    tmpDir: real\n"
        );

        $this->assertSame('real', PhpstanCacheResolver::resolve($path));
    }

    /** @test */
    public function multiple_tmpdir_in_same_file_picks_the_last_one()
    {
        $path = $this->writeNeon(
            'multi.neon',
            "parameters:\n    tmpDir: first\nparameters:\n    tmpDir: second\n"
        );

        $this->assertSame('second', PhpstanCacheResolver::resolve($path));
    }

    /** @test */
    public function commented_include_line_is_ignored()
    {
        $this->writeNeon('base.neon', "parameters:\n    tmpDir: from_base\n");
        $this->writeNeon('other.neon', "parameters:\n    tmpDir: from_other\n");
        $entry = $this->writeNeon('entry.neon', "includes:\n#    - base.neon\n    - other.neon\n");

        $this->assertSame('from_other', PhpstanCacheResolver::resolve($entry));
    }

    /** @test */
    public function include_path_with_dot_dot_resolves_correctly()
    {
        mkdir($this->sandbox . '/sub', 0755, true);
        $this->writeNeon('base.neon', "parameters:\n    tmpDir: from_base\n");
        $childPath = $this->sandbox . '/sub/child.neon';
        file_put_contents($childPath, "includes:\n    - ../base.neon\n");
        $this->paths[] = $childPath;

        $this->assertSame('from_base', PhpstanCacheResolver::resolve($childPath));

        @rmdir($this->sandbox . '/sub');
    }

    /** @test */
    public function tmpdir_with_quoted_path_strips_quotes_only()
    {
        $path = $this->writeNeon('quoted.neon', "parameters:\n    tmpDir: '/path with spaces/cache'\n");

        $this->assertSame('/path with spaces/cache', PhpstanCacheResolver::resolve($path));
    }

    /** @test */
    public function unknown_placeholder_is_left_literal_for_caller_to_decide()
    {
        // The resolver intentionally leaves unrecognised placeholders alone.
        // PhpstanJob detects '%' in the result and emits the warning instead.
        $path = $this->writeNeon('env.neon', "parameters:\n    tmpDir: %env.HOME%/cache\n");

        $this->assertSame('%env.HOME%/cache', PhpstanCacheResolver::resolve($path));
    }

    /** @test */
    public function absolute_include_path_is_used_verbatim()
    {
        $base = $this->writeNeon('abs-base.neon', "parameters:\n    tmpDir: abs/cache\n");
        $child = $this->writeNeon('abs-child.neon', "includes:\n    - $base\n");

        $this->assertSame('abs/cache', PhpstanCacheResolver::resolve($child));
    }

    /**
     *       L2164 / L2177 (anchors on `/^parameters:\s*$/`),
     *       L2034 (`$inIncludes = false` initial), L2138 (`$insideParameters = false` initial),
     *       L2086 (anchor `^` on the entry regex `^\s+-\s+…`),
     *       L2125 (`$inIncludes = false` on exit-includes branch),
     *       L2202 / L2215 (IncrementInteger on `$line[0]` in isTopLevelKey).
     *
     * Each row exercises a single anchor / initial-flag invariant.
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    public function neonAnchorAndFlagProvider(): array
    {
        return [
            // L2047 `^includes:` — caret anchors at line start; a leading `#` must not match.
            'commented-out includes is ignored' => [
                "# includes:\n    - missing.neon\nparameters:\n    tmpDir: /tmp/anchor-caret\n",
                '/tmp/anchor-caret',
            ],
            // L2060 `includes:\s*$` — dollar anchors at line end; trailing content disables the marker.
            'includes with trailing content is not a section marker' => [
                "includes: not-a-section\nparameters:\n    tmpDir: /tmp/anchor-dollar\n",
                '/tmp/anchor-dollar',
            ],
            // L2164 / L2177 `^parameters:\s*$` — same logic for the tmpDir section.
            'parameters with trailing content is not a section marker' => [
                "parameters: not-a-section\nignored: 1\n",
                null,
            ],
            // L2086 `^\s+-\s+` — caret on the entry regex; an entry line without indentation is ignored.
            'entry without leading indentation is not an include' => [
                "includes:\n- looks-like-entry\nparameters:\n    tmpDir: /tmp/entry-caret\n",
                '/tmp/entry-caret',
            ],
            // L2034 `$inIncludes = false` initial — tmpDir before any "includes:" marker stays captured.
            'tmpDir-only config without includes block works' => [
                "parameters:\n    tmpDir: /tmp/no-includes\n",
                '/tmp/no-includes',
            ],
            // L2138 `$insideParameters = false` initial — a tmpDir BEFORE the parameters block must NOT be captured.
            'tmpDir before parameters block is ignored' => [
                "tmpDir: /should/be/ignored\nparameters:\n    tmpDir: /real/tmp\n",
                '/real/tmp',
            ],
            // L2125 `$inIncludes = false` on exit — `includes:` followed by a top-level key must close the block.
            'top-level key after includes closes the section' => [
                "includes:\n    - a.neon\nother:\n    tmpDir: should-not-match\nparameters:\n    tmpDir: /tmp/exit-includes\n",
                '/tmp/exit-includes',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider neonAnchorAndFlagProvider
     */
    public function neon_parser_respects_anchors_and_initial_flags(string $contents, ?string $expectedTmpDir): void
    {
        $path = $this->writeNeon('anchors-' . uniqid('', true) . '.neon', $contents);
        $this->assertSame($expectedTmpDir, PhpstanCacheResolver::resolve($path));
    }

    /**
     * quoted include values must be unquoted before being resolved.
     *
     * @test
     */
    public function quoted_include_values_are_stripped_of_surrounding_quotes(): void
    {
        $base = $this->writeNeon('quoted-base.neon', "parameters:\n    tmpDir: /tmp/quoted\n");
        $childDouble = $this->writeNeon('quoted-child-double.neon', "includes:\n    - \"$base\"\n");
        $childSingle = $this->writeNeon('quoted-child-single.neon', "includes:\n    - '$base'\n");

        $this->assertSame('/tmp/quoted', PhpstanCacheResolver::resolve($childDouble));
        $this->assertSame('/tmp/quoted', PhpstanCacheResolver::resolve($childSingle));
    }

    /**
     * `ltrim($line)` to detect comments with leading whitespace, and the
     * exit-includes path relies on `ltrim($line)` to skip indented comments.
     *
     * Without ltrim, an indented `# comment` keeps its leading space as
     * `$trimmed[0]` and the comment check fails, breaking the iteration.
     *
     * @test
     */
    public function indented_comments_inside_parameters_are_skipped_without_aborting_the_parse(): void
    {
        $config = "parameters:\n"
            . "    # commented-out level\n"
            . "    # another comment\n"
            . "    tmpDir: /real/tmp\n";
        $path = $this->writeNeon('indented-comments.neon', $config);

        $this->assertSame('/real/tmp', PhpstanCacheResolver::resolve($path));
    }

    /**
     * `isTopLevelKey` must look at the very first character.
     *
     * @test
     */
    public function is_top_level_key_uses_the_first_character_strictly(): void
    {
        $reflection = new \ReflectionMethod(PhpstanCacheResolver::class, 'isTopLevelKey');
        $reflection->setAccessible(true);

        // No-indent first char ⇒ top level. Mutant L2202 looks at $line[1]
        // (which is 'a' for "parameters:") and still returns true — so
        // a string whose [1] is whitespace must distinguish:
        $this->assertTrue($reflection->invoke(null, 'parameters:'));
        // First char is space ⇒ NOT top level. Original: ctype_space(' ') true → !true = false → returns false.
        // Mutant L2202 looks at [1] ('a' in "  arameters"): ctype_space false → may pass.
        $this->assertFalse($reflection->invoke(null, '    tmpDir: x'));
        $this->assertFalse($reflection->invoke(null, "\ttmpDir: x"));
        // Adversarial: first char is non-space but [1] is space — mutant L2202
        // would say "indented" and return false. Original returns true.
        $this->assertTrue($reflection->invoke(null, 'p arameters: weird-but-top-level'));
        // L2215: $line[1] !== "\t" must be tested too. A line starting with
        // a single tab is NOT top level — both original and mutant agree.
        // What distinguishes them: a line whose [0] is non-space, non-tab,
        // and [1] is a tab. Original returns true; mutant L2215 says
        // $line[1] === "\t" so the second clause is false → returns false.
        $this->assertTrue($reflection->invoke(null, "x\tnot-indented"));
    }

    private function writeNeon(string $name, string $content): string
    {
        $path = $this->sandbox . '/' . $name;
        file_put_contents($path, $content);
        $this->paths[] = $path;
        return $path;
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->removeDir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}
