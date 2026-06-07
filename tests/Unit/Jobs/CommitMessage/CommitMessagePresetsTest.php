<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\CommitMessage;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Jobs\CommitMessage\CommitMessagePresets;

/**
 * Preset catalogue + per-key override resolution (REQ-012, FEAT-16).
 */
class CommitMessagePresetsTest extends UnitTestCase
{
    /** @test */
    public function conventional_commits_is_known(): void
    {
        $this->assertTrue(CommitMessagePresets::isKnown('conventional-commits'));
        $this->assertContains('conventional-commits', CommitMessagePresets::names());
    }

    /** @test */
    public function unknown_preset_is_not_known(): void
    {
        $this->assertFalse(CommitMessagePresets::isKnown('gitmoji'));
    }

    /** @test */
    public function resolve_without_preset_returns_explicit_rules(): void
    {
        $rules = CommitMessagePresets::resolve(null, ['min-length' => 5]);

        $this->assertSame(['min-length' => 5], $rules);
    }

    /** @test */
    public function resolve_preset_only_returns_preset_rules(): void
    {
        $rules = CommitMessagePresets::resolve('conventional-commits', []);

        $this->assertSame(100, $rules['max-length']);
        $this->assertSame('lowercase', $rules['subject-case']);
        $this->assertTrue($rules['forbid-trailing-period']);
    }

    /** @test */
    public function explicit_rule_overrides_preset_key_by_key(): void
    {
        // Override max-length and disable subject-case; the rest of the preset
        // stays active (REQ-012).
        $rules = CommitMessagePresets::resolve('conventional-commits', [
            'max-length'   => 120,
            'subject-case' => null,
        ]);

        $this->assertSame(120, $rules['max-length']);
        $this->assertNull($rules['subject-case']);
        // Untouched preset entries remain.
        $this->assertSame(10, $rules['min-length']);
        $this->assertTrue($rules['forbid-trailing-period']);
        $this->assertArrayHasKey('pattern', $rules);
    }
}
