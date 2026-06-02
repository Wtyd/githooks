<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs\CommitMessage;

/**
 * Closed catalogue of commit-message rule presets (FEAT-16). A preset is a
 * named bundle of rules that expands to the same shape a user could write by
 * hand under `jobs.<name>.rules`.
 *
 * v3.5 ships a single preset, `conventional-commits`. Adding another
 * (gitmoji, jira-ticket) is ~one entry here, but is deliberately out of scope
 * until real demand appears (CON-002).
 *
 * `pattern-example` is a preset-internal hint surfaced as the `example` field
 * on a pattern failure; it has no canonical value for a user-supplied pattern,
 * which is why a custom `pattern` carries no example (REQ-017).
 */
final class CommitMessagePresets
{
    private const PRESETS = [
        'conventional-commits' => [
            'min-length'             => 10,
            'max-length'             => 100,
            'pattern'                => '/^(feat|fix|test|docs|refactor|chore|ci|build|perf|style|revert)(\([\w-]+\))?(!)?: .+/',
            'pattern-message'        => 'Use Conventional Commits: tipo(scope?)!?: descripción.',
            'pattern-example'        => 'feat(api): add user endpoint',
            'forbid-trailing-period' => true,
            'subject-case'           => 'lowercase',
            'forbid-empty'           => true,
            'merge-allowed'          => true,
        ],
    ];

    public static function isKnown(string $preset): bool
    {
        return array_key_exists($preset, self::PRESETS);
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::PRESETS);
    }

    /**
     * The raw rule bundle of a preset. Caller must guarantee the preset exists
     * (config validation rejects unknown presets before this is reached).
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(string $preset): array
    {
        return self::PRESETS[$preset] ?? [];
    }

    /**
     * Resolve the effective rule set: start from the preset (if any), then let
     * explicitly-declared rules override the preset entry by entry (REQ-012).
     * A preset rule the user does not touch stays active; a user rule with no
     * preset counterpart is simply added.
     *
     * @param array<string, mixed> $explicitRules
     * @return array<string, mixed>
     */
    public static function resolve(?string $preset, array $explicitRules): array
    {
        $base = $preset !== null ? self::rulesFor($preset) : [];
        // Per-key override (not deep merge): explicit entries win, preset
        // entries the user did not declare remain.
        return array_merge($base, $explicitRules);
    }
}
