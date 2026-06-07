<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use Tests\Utils\TestCase\UnitTestCase;

/**
 * Unit tests for the BUG-21 concern. Cover the adversarial casillas of the
 * decision table that don't fit cleanly into the system-level tests of
 * JobCommandTest: malformed tokens, clustering, `=` inside values, dedupe.
 *
 * The double drives the concern via a synthetic `ArgvInput`, matching the
 * production path (the trait reads tokens from `$this->input`, not from
 * `$_SERVER['argv']`).
 */
class ValidatesUnknownOptionsBeforeDashDashTest extends UnitTestCase
{
    private const BIN = ['githooks', 'job', 'phpcs_src'];

    /** @test */
    public function returns_true_when_no_args_present(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble(['config' => null]);

        $this->assertTrue($double->call(self::BIN));
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function returns_true_for_known_long_option_with_value(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble(['config' => null]);

        $this->assertTrue($double->call(array_merge(self::BIN, ['--config=/tmp/x.php'])));
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function returns_true_for_known_long_option_without_value(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble(['dry-run' => null]);

        $this->assertTrue($double->call(array_merge(self::BIN, ['--dry-run'])));
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function returns_false_on_unknown_long_option_with_value(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble(['config' => null]);

        $this->assertFalse($double->call(array_merge(self::BIN, ['--foo=bar', '--config=/tmp/x.php'])));
        $this->assertContains('The "--foo" option does not exist.', $double->errLines);
    }

    /** @test */
    public function returns_false_on_unknown_long_option_without_value(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble();

        $this->assertFalse($double->call(array_merge(self::BIN, ['--foo'])));
        $this->assertContains('The "--foo" option does not exist.', $double->errLines);
    }

    /** @test */
    public function returns_false_on_unknown_short_option(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble();

        $this->assertFalse($double->call(array_merge(self::BIN, ['-x'])));
        $this->assertContains('The "-x" option does not exist.', $double->errLines);
    }

    /** @test */
    public function returns_true_for_known_short_option(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble(['verbose' => 'v']);

        $this->assertTrue($double->call(array_merge(self::BIN, ['-v'])));
        $this->assertSame([], $double->errLines);
    }

    /**
     * Adversarial — every char in a short-option cluster is checked.
     * `-vvv` (all known) passes; `-vxz` (with unknowns) reports `-x` and `-z`.
     *
     * @test
     */
    public function short_option_cluster_validates_each_char(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble(['verbose' => 'v']);

        $this->assertTrue($double->call(array_merge(self::BIN, ['-vvv'])));
        $this->assertSame([], $double->errLines);

        $double->errLines = [];
        $this->assertFalse($double->call(array_merge(self::BIN, ['-vxz'])));
        $this->assertContains('The "-x" option does not exist.', $double->errLines);
        $this->assertContains('The "-z" option does not exist.', $double->errLines);
    }

    /**
     * Adversarial — tokens after `--` are passthrough; their names are
     * irrelevant, even if they look like unknown options.
     *
     * @test
     */
    public function tokens_after_dash_dash_are_ignored(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble(['config' => null]);

        $this->assertTrue($double->call(array_merge(self::BIN, [
            '--config=/tmp/x.php',
            '--',
            '--filter=Foo', '--invalid-flag', '-z',
        ])));
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function reports_all_unknown_options_in_one_pass(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble();

        $this->assertFalse($double->call(array_merge(self::BIN, ['--foo', '--bar'])));
        $this->assertContains('The "--foo" option does not exist.', $double->errLines);
        $this->assertContains('The "--bar" option does not exist.', $double->errLines);
    }

    /** @test */
    public function duplicate_unknown_options_are_reported_once(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble();

        $this->assertFalse($double->call(array_merge(self::BIN, ['--foo', '--foo'])));
        $this->assertSame(['The "--foo" option does not exist.'], $double->errLines);
    }

    /**
     * Adversarial — orphan `--` at the end of the line means "no args after",
     * not an unknown option.
     *
     * @test
     */
    public function dash_dash_orphan_is_not_reported_as_unknown(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble();

        $this->assertTrue($double->call(array_merge(self::BIN, ['--'])));
        $this->assertSame([], $double->errLines);
    }

    /**
     * Adversarial — value with internal `=` (path with `=` chars). Only the
     * option name before the first `=` is looked up.
     *
     * @test
     */
    public function long_option_value_with_internal_equals_is_handled(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble(['config' => null]);

        $this->assertTrue($double->call(array_merge(self::BIN, ['--config=path/with=equals'])));
        $this->assertSame([], $double->errLines);
    }

    /**
     * Adversarial — positional args (no leading `-`) are not validated by the
     * concern. They are the host command's responsibility (e.g. the job name).
     *
     * @test
     */
    public function positional_arguments_are_not_validated(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble();

        $this->assertTrue($double->call(array_merge(self::BIN, ['extra_positional'])));
        $this->assertSame([], $double->errLines);
    }

    /** @test */
    public function input_contains_dash_dash_separator_detects_token(): void
    {
        $double = new ValidatesUnknownOptionsBeforeDashDashCommandDouble();

        $this->assertTrue($double->callDashDashCheck(array_merge(self::BIN, ['--', 'after'])));
        $this->assertFalse($double->callDashDashCheck(array_merge(self::BIN, ['--config=X'])));
    }
}
