<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\CacheResolver;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Jobs\CacheResolver\PhpConfigCacheResolver;

/**
 * Covers the static-best-effort resolver shared by RectorJob and PhpCsFixerJob.
 * Factor table:
 *   - method name: cacheDirectory / setCacheFile / non-existent
 *   - argument shape: literal / __DIR__ . 'literal' / sys_get_temp_dir() . 'literal'
 *                     / variable / function call other than the two we recognize
 *   - quotes: single / double
 *   - file presence: missing / present
 */
class PhpConfigCacheResolverTest extends UnitTestCase
{
    /** @var string[] */
    private array $paths = [];

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/php-config-resolver-' . uniqid('', true);
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
            @rmdir($this->sandbox);
        }
        parent::tearDown();
    }

    /** @test */
    public function returns_null_when_file_does_not_exist()
    {
        $this->assertNull(PhpConfigCacheResolver::resolve($this->sandbox . '/missing.php', 'cacheDirectory'));
    }

    /** @test */
    public function returns_null_when_method_is_not_called_in_file()
    {
        $path = $this->writePhp('plain.php', "<?php\nreturn function (\$config) { \$config->paths(['src']); };\n");

        $this->assertNull(PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
    }

    /**
     * @test
     * @dataProvider literalAndPlaceholderArgs
     */
    public function resolves_literal_and_placeholder_arguments(string $argSource, string $expectedSuffix, string $expectedPrefix)
    {
        $path = $this->writePhp(
            'rector.php',
            "<?php\nreturn function (\$config) { \$config->cacheDirectory($argSource); };\n"
        );

        $expected = $this->expectedPath($expectedPrefix, $path, $expectedSuffix);
        $this->assertSame($expected, PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function literalAndPlaceholderArgs(): iterable
    {
        yield 'absolute literal single-quoted' => ["'/var/cache/rector'", '/var/cache/rector', 'literal'];
        yield 'absolute literal double-quoted' => ['"/var/cache/rector"', '/var/cache/rector', 'literal'];
        yield '__DIR__ concat single' => ["__DIR__ . '/.rector-cache'", '/.rector-cache', 'config-dir'];
        yield '__DIR__ concat double' => ['__DIR__ . "/.rector-cache"', '/.rector-cache', 'config-dir'];
        yield 'sys_get_temp_dir concat' => ["\\sys_get_temp_dir() . '/rector_cache'", '/rector_cache', 'sys-temp'];
        yield 'sys_get_temp_dir concat unprefixed' => ["sys_get_temp_dir() . '/rector_cache'", '/rector_cache', 'sys-temp'];
    }

    /**
     * @test
     * @dataProvider unresolvableArgs
     */
    public function returns_null_for_dynamic_arguments(string $argSource)
    {
        $path = $this->writePhp(
            'rector.php',
            "<?php\nreturn function (\$config) { \$config->cacheDirectory($argSource); };\n"
        );

        $this->assertNull(PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
        $this->assertTrue(PhpConfigCacheResolver::declaresUnresolvable($path, 'cacheDirectory'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function unresolvableArgs(): iterable
    {
        yield 'plain variable' => ['$cacheDir'];
        yield 'env helper' => ['getenv("RECTOR_CACHE")'];
        yield 'helper call' => ['cachePath()'];
        yield 'dirname concat' => ['dirname(__DIR__) . "/.cache"'];
    }

    /** @test */
    public function declares_unresolvable_returns_false_when_method_not_present()
    {
        $path = $this->writePhp('plain.php', "<?php\nreturn function (\$config) {};\n");

        $this->assertFalse(PhpConfigCacheResolver::declaresUnresolvable($path, 'cacheDirectory'));
    }

    /** @test */
    public function ignores_method_calls_inside_line_comments()
    {
        $path = $this->writePhp(
            'commented.php',
            "<?php\nreturn function (\$c) {\n    // \$c->cacheDirectory('/wrong');\n    \$c->cacheDirectory('/right');\n};\n"
        );

        $this->assertSame('/right', PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
    }

    /** @test */
    public function ignores_method_calls_inside_hash_comments()
    {
        $path = $this->writePhp(
            'hash.php',
            "<?php\nreturn function (\$c) {\n    # \$c->cacheDirectory('/wrong');\n    \$c->cacheDirectory('/right');\n};\n"
        );

        $this->assertSame('/right', PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
    }

    /** @test */
    public function ignores_method_calls_inside_block_comments()
    {
        $path = $this->writePhp(
            'block.php',
            "<?php\nreturn function (\$c) {\n    /* \$c->cacheDirectory('/wrong');\n       extra junk */\n    \$c->cacheDirectory('/right');\n};\n"
        );

        $this->assertSame('/right', PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
    }

    /** @test */
    public function declares_unresolvable_is_false_when_only_call_is_commented_out()
    {
        $path = $this->writePhp(
            'only-commented.php',
            "<?php\nreturn function (\$c) {\n    // \$c->cacheDirectory(\$dynamic);\n};\n"
        );

        $this->assertFalse(PhpConfigCacheResolver::declaresUnresolvable($path, 'cacheDirectory'));
    }

    /** @test */
    public function multiple_resolvable_calls_pick_the_last_in_file_order()
    {
        $path = $this->writePhp(
            'override.php',
            "<?php\nreturn function (\$c) {\n    \$c->cacheDirectory('/first');\n    \$c->cacheDirectory('/second');\n};\n"
        );

        $this->assertSame('/second', PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
    }

    /** @test */
    public function override_across_different_argument_shapes_picks_last_by_file_position()
    {
        $path = $this->writePhp(
            'mixed-override.php',
            "<?php\nreturn function (\$c) {\n    \$c->cacheDirectory(__DIR__ . '/.first');\n    \$c->cacheDirectory('/second');\n};\n"
        );

        $this->assertSame('/second', PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
    }

    /** @test */
    public function dynamic_last_call_overrides_a_resolvable_earlier_call_with_null()
    {
        // Runtime behaviour: the LAST call wins. If the last call is dynamic
        // and unparseable, we don't know the effective path even though an
        // earlier call was resolvable — return null to surface the unknown
        // rather than a stale path the user is no longer using.
        $path = $this->writePhp(
            'static-then-dynamic.php',
            "<?php\nreturn function (\$c) {\n    \$c->cacheDirectory('/static');\n    \$c->cacheDirectory(\$dynamic);\n};\n"
        );

        $this->assertNull(PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
        $this->assertTrue(PhpConfigCacheResolver::declaresUnresolvable($path, 'cacheDirectory'));
    }

    /** @test */
    public function dynamic_first_call_does_not_void_a_resolvable_last_call()
    {
        // Symmetric to the previous case: if the LAST call is resolvable,
        // earlier dynamic calls don't matter — runtime keeps the last value.
        $path = $this->writePhp(
            'dynamic-then-static.php',
            "<?php\nreturn function (\$c) {\n    \$c->cacheDirectory(\$dynamic);\n    \$c->cacheDirectory('/static');\n};\n"
        );

        $this->assertSame('/static', PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
        $this->assertFalse(PhpConfigCacheResolver::declaresUnresolvable($path, 'cacheDirectory'));
    }

    /** @test */
    public function whitespace_around_method_call_is_tolerated()
    {
        $path = $this->writePhp(
            'whitespace.php',
            "<?php\nreturn function (\$c) {\n    \$c  ->  cacheDirectory  (   '/spaced'   ) ;\n};\n"
        );

        $this->assertSame('/spaced', PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
    }

    /** @test */
    public function multiline_method_call_resolves_correctly()
    {
        $path = $this->writePhp(
            'multiline.php',
            "<?php\nreturn function (\$c) {\n    \$c->cacheDirectory(\n        __DIR__ . '/.cache'\n    );\n};\n"
        );

        $this->assertSame(dirname(realpath($path)) . '/.cache', PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
    }

    /** @test */
    public function unrelated_method_calls_with_substring_match_are_not_picked()
    {
        // A method named "configureCacheDirectory" should NOT match "cacheDirectory".
        $path = $this->writePhp(
            'substring.php',
            "<?php\nreturn function (\$c) {\n    \$c->configureCacheDirectory('/decoy');\n};\n"
        );

        $this->assertNull(PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
    }

    /** @test */
    public function works_with_setCacheFile_method_name_for_php_cs_fixer()
    {
        $path = $this->writePhp(
            '.php-cs-fixer.php',
            "<?php\nreturn (new PhpCsFixer\\Config())->setCacheFile(__DIR__ . '/qa/.fixer.cache');\n"
        );

        $expected = dirname(realpath($path)) . '/qa/.fixer.cache';
        $this->assertSame($expected, PhpConfigCacheResolver::resolve($path, 'setCacheFile'));
    }

    /**
     * a method name containing a regex metacharacter (e.g. `.`) must be treated
     * as a literal. Without preg_quote, `set.Cache` would match any character
     * between `set` and `Cache`, so `->setMyCache(__DIR__ . '/x')` would match
     * the regex for method name `set.Cache` and resolve a path. The fix
     * (preg_quote) prevents that false positive.
     *
     * @test
     */
    public function method_name_with_regex_metacharacter_does_not_match_unrelated_call(): void
    {
        $path = $this->writePhp(
            'meta-quote.php',
            "<?php\nreturn function (\$config) { \$config->setMyCache(__DIR__ . '/x'); };\n"
        );

        // Original: preg_quote('set.Cache', '/') = 'set\.Cache' — no match.
        // Mutant: no quoting; '.' matches any character, so `setMyCache`
        // (M matches the dot) WOULD resolve.
        $this->assertNull(PhpConfigCacheResolver::resolve($path, 'set.Cache'));

        // Same logic for declaresUnresolvable.
        $this->assertFalse(PhpConfigCacheResolver::declaresUnresolvable($path, 'set.Cache'));
    }

    /**
     * the full match (position 0) is what the resolver compares against
     * `$lastCallOffset` for tie-breaking. If the index is swapped to a
     * capture group, the position-matching breaks and the resolver returns
     * the wrong hit when there are multiple calls.
     *
     * Strategy: two `cacheDirectory(__DIR__ . '/x')` calls. The resolver
     * must return the LAST one; if `$matches[0]` is swapped to `$matches[1]`
     * (the inner string capture, which has its own offsets that point INSIDE
     * the call, not at the `->` start), the tie-break fails.
     *
     * @test
     */
    public function multiple_dir_concat_calls_resolve_to_the_last_by_file_position(): void
    {
        $path = $this->writePhp(
            'last-dir.php',
            "<?php\nreturn function (\$config) {\n"
            . "    \$config->cacheDirectory(__DIR__ . '/first');\n"
            . "    \$config->cacheDirectory(__DIR__ . '/last');\n"
            . "};\n"
        );

        $expected = dirname(realpath($path)) . '/last';
        $this->assertSame($expected, PhpConfigCacheResolver::resolve($path, 'cacheDirectory'));
    }

    /**
     * for the single-quote capture, and `$matches[2]` ↔ `$matches[1]` for the
     * double-quote capture): each quoted literal must be picked from its own
     * capture group, not from the full match or the other quote group.
     *
     * Strategy: mix single-quoted and double-quoted literals in the same
     * file. If the index swap returns the wrong capture, the resolved path
     * either contains the literal `'…'` quotes (full match) or the wrong
     * literal content.
     *
     * @test
     */
    public function quote_style_is_resolved_from_its_own_capture_group(): void
    {
        $single = $this->writePhp(
            'single.php',
            "<?php\nreturn function (\$config) { \$config->cacheDirectory('/single-quoted'); };\n"
        );
        $double = $this->writePhp(
            'double.php',
            '<?php' . "\n" . 'return function ($config) { $config->cacheDirectory("/double-quoted"); };' . "\n"
        );

        // Both must resolve to the LITERAL string, not to the full call match
        // (which includes "->cacheDirectory(...)"). A swap to $matches[0]
        // would surface the entire call substring; a swap between [1] and [2]
        // would leave the value empty for one of the quote styles.
        $this->assertSame('/single-quoted', PhpConfigCacheResolver::resolve($single, 'cacheDirectory'));
        $this->assertSame('/double-quoted', PhpConfigCacheResolver::resolve($double, 'cacheDirectory'));
    }

    /**
     * when realpath succeeds the dir-concat base must be the realpath
     * directory, not the original (possibly relative) configPath dirname.
     *
     * Strategy: invoke the resolver with a path whose realpath differs from
     * its dirname (a symlink). Hard to set up portably — fall back to a
     * relative-path scenario: chdir into the sandbox and pass `./file.php`.
     * realpath resolves to the absolute sandbox; dirname('./file.php') is '.'.
     *
     * @test
     */
    public function dir_concat_base_uses_realpath_when_config_path_is_relative(): void
    {
        $path = $this->writePhp(
            'rel-base.php',
            "<?php\nreturn function (\$config) { \$config->cacheDirectory(__DIR__ . '/cache'); };\n"
        );
        $cwd = (string) getcwd();
        chdir($this->sandbox);
        try {
            $relative = './' . basename($path);
            $resolved = PhpConfigCacheResolver::resolve($relative, 'cacheDirectory');

            // Original: dirname(realpath('./rel-base.php')) = absolute sandbox.
            // Mutant: dirname('./rel-base.php') = '.', so $resolved = './cache'.
            $this->assertNotSame('./cache', $resolved);
            $this->assertStringEndsWith('/cache', (string) $resolved);
            $this->assertStringStartsWith('/', (string) $resolved);
        } finally {
            chdir($cwd);
        }
    }

    private function writePhp(string $name, string $content): string
    {
        $path = $this->sandbox . '/' . $name;
        file_put_contents($path, $content);
        $this->paths[] = $path;
        return $path;
    }

    private function expectedPath(string $kind, string $configPath, string $suffix): string
    {
        switch ($kind) {
            case 'literal':
                return $suffix;
            case 'config-dir':
                return dirname(realpath($configPath)) . $suffix;
            case 'sys-temp':
                return sys_get_temp_dir() . $suffix;
            default:
                throw new \InvalidArgumentException("Unknown expected kind: $kind");
        }
    }
}
