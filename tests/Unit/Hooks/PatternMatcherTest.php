<?php

declare(strict_types=1);

namespace Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Hooks\PatternMatcher;

/**
 * Direct unit tests for PatternMatcher extracted from HookRunner.
 * Tests each method independently without full HookRunner setup.
 */
class PatternMatcherTest extends TestCase
{
    private PatternMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new PatternMatcher();
    }

    // ========================================================================
    // globToRegex
    // ========================================================================

    /** @test */
    public function globToRegex_simple_star()
    {
        $regex = $this->matcher->globToRegex('src/*.php');
        $this->assertMatchesRegularExpression($regex, 'src/File.php');
        $this->assertDoesNotMatchRegularExpression($regex, 'src/sub/File.php');
    }

    /** @test */
    public function globToRegex_doublestar_between_slashes()
    {
        $regex = $this->matcher->globToRegex('src/**/File.php');
        $this->assertMatchesRegularExpression($regex, 'src/File.php');         // zero dirs
        $this->assertMatchesRegularExpression($regex, 'src/a/File.php');       // one dir
        $this->assertMatchesRegularExpression($regex, 'src/a/b/c/File.php');   // multiple dirs
    }

    /** @test */
    public function globToRegex_doublestar_at_end()
    {
        $regex = $this->matcher->globToRegex('src/**');
        $this->assertMatchesRegularExpression($regex, 'src/File.php');
        $this->assertMatchesRegularExpression($regex, 'src/a/b/File.php');
        $this->assertDoesNotMatchRegularExpression($regex, 'vendor/File.php');
    }

    /** @test */
    public function globToRegex_doublestar_at_start()
    {
        $regex = $this->matcher->globToRegex('**/*.php');
        $this->assertMatchesRegularExpression($regex, 'File.php');
        $this->assertMatchesRegularExpression($regex, 'src/File.php');
        $this->assertMatchesRegularExpression($regex, 'src/a/b/File.php');
        $this->assertDoesNotMatchRegularExpression($regex, 'File.txt');
    }

    /** @test */
    public function globToRegex_doublestar_alone()
    {
        $regex = $this->matcher->globToRegex('**');
        $this->assertMatchesRegularExpression($regex, 'anything');
        $this->assertMatchesRegularExpression($regex, 'src/a/b/c.php');
    }

    /** @test */
    public function globToRegex_multiple_doublestars()
    {
        $regex = $this->matcher->globToRegex('src/**/models/**/User.php');
        $this->assertMatchesRegularExpression($regex, 'src/models/User.php');
        $this->assertMatchesRegularExpression($regex, 'src/app/models/v2/User.php');
        $this->assertDoesNotMatchRegularExpression($regex, 'vendor/models/User.php');
    }

    /** @test */
    public function globToRegex_question_mark()
    {
        $regex = $this->matcher->globToRegex('src/?.php');
        $this->assertMatchesRegularExpression($regex, 'src/A.php');
        $this->assertDoesNotMatchRegularExpression($regex, 'src/AB.php');
        $this->assertDoesNotMatchRegularExpression($regex, 'src//.php');
    }

    // ========================================================================
    // fileMatchesPattern
    // ========================================================================

    /** @test */
    public function fileMatchesPattern_without_doublestar_uses_fnm_pathname()
    {
        $this->assertTrue($this->matcher->fileMatchesPattern('src/File.php', 'src/*.php'));
        $this->assertFalse($this->matcher->fileMatchesPattern('src/sub/File.php', 'src/*.php'));
    }

    /** @test */
    public function fileMatchesPattern_with_doublestar_uses_regex()
    {
        $this->assertTrue($this->matcher->fileMatchesPattern('src/sub/File.php', 'src/**/*.php'));
        $this->assertFalse($this->matcher->fileMatchesPattern('vendor/File.php', 'src/**/*.php'));
    }

    // ========================================================================
    // matchesBranch
    // ========================================================================

    /** @test */
    public function matchesBranch_empty_branch_returns_false()
    {
        $this->assertFalse($this->matcher->matchesBranch('', ['main'], []));
    }

    /** @test */
    public function matchesBranch_exact_match()
    {
        $this->assertTrue($this->matcher->matchesBranch('main', ['main'], []));
    }

    /** @test */
    public function matchesBranch_fnmatch_pattern()
    {
        $this->assertTrue($this->matcher->matchesBranch('feature/login', ['feature/*'], []));
    }

    /** @test */
    public function matchesBranch_no_match()
    {
        $this->assertFalse($this->matcher->matchesBranch('hotfix/x', ['feature/*', 'main'], []));
    }

    /** @test */
    public function matchesBranch_empty_includes_matches_all()
    {
        $this->assertTrue($this->matcher->matchesBranch('anything', [], []));
    }

    /** @test */
    public function matchesBranch_exclude_prevails()
    {
        $this->assertFalse($this->matcher->matchesBranch('release/beta', ['release/*'], ['release/beta*']));
    }

    /** @test */
    public function matchesBranch_exclude_exact_prevails()
    {
        $this->assertFalse($this->matcher->matchesBranch('main', ['main'], ['main']));
    }

    /** @test */
    public function matchesBranch_include_matches_exclude_does_not()
    {
        $this->assertTrue($this->matcher->matchesBranch('release/v2', ['release/*'], ['release/beta*']));
    }

    /** @test */
    public function matchesBranch_empty_include_with_exclude()
    {
        // Empty include = match all → then exclude kicks in
        $this->assertFalse($this->matcher->matchesBranch('temp', [], ['temp']));
        $this->assertTrue($this->matcher->matchesBranch('main', [], ['temp']));
    }

    // ========================================================================
    // matchesFiles
    // ========================================================================

    /** @test */
    public function matchesFiles_empty_files_returns_false()
    {
        $this->assertFalse($this->matcher->matchesFiles([], ['src/*.php'], []));
    }

    /** @test */
    public function matchesFiles_matching_file()
    {
        $this->assertTrue($this->matcher->matchesFiles(['src/Foo.php'], ['src/*.php'], []));
    }

    /** @test */
    public function matchesFiles_no_matching_file()
    {
        $this->assertFalse($this->matcher->matchesFiles(['vendor/auto.php'], ['src/*.php'], []));
    }

    /** @test */
    public function matchesFiles_excluded_file()
    {
        $this->assertFalse($this->matcher->matchesFiles(['src/Foo.php'], ['src/*.php'], ['src/Foo.php']));
    }

    /** @test */
    public function matchesFiles_one_excluded_one_not()
    {
        $this->assertTrue($this->matcher->matchesFiles(
            ['src/Excluded.php', 'src/Allowed.php'],
            ['src/*.php'],
            ['src/Excluded.php']
        ));
    }

    /** @test */
    public function matchesFiles_all_excluded()
    {
        $this->assertFalse($this->matcher->matchesFiles(
            ['src/A.php', 'src/B.php'],
            ['src/*.php'],
            ['src/*.php']
        ));
    }

    /** @test */
    public function matchesFiles_empty_includes_matches_all_then_excludes()
    {
        // Empty includes = all match → then exclude applies
        $this->assertFalse($this->matcher->matchesFiles(['vendor/x.php'], [], ['vendor/*.php']));
        $this->assertTrue($this->matcher->matchesFiles(['src/x.php'], [], ['vendor/*.php']));
    }

    /** @test */
    public function matchesFiles_with_doublestar_pattern()
    {
        $this->assertTrue($this->matcher->matchesFiles(['src/a/b/File.php'], ['src/**/*.php'], []));
        $this->assertFalse($this->matcher->matchesFiles(['vendor/File.php'], ['src/**/*.php'], []));
    }
}
