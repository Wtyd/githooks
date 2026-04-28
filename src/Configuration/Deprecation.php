<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

/**
 * Structured record of a single deprecated key detected during config parsing.
 *
 * Emitted in parallel to the legacy string warning so that machine consumers
 * (JSON v2, SARIF) can categorize and act on deprecations without parsing text.
 * Humans keep reading the string warning produced via ValidationResult::addWarning().
 */
final class Deprecation
{
    public const KIND_CONFIG_KEY_RENAME = 'config-key-rename';

    private string $job;

    private string $oldKey;

    private string $newKey;

    private string $removalVersion;

    private string $kind;

    public function __construct(
        string $job,
        string $oldKey,
        string $newKey,
        string $removalVersion = 'v4.0',
        string $kind = self::KIND_CONFIG_KEY_RENAME
    ) {
        $this->job = $job;
        $this->oldKey = $oldKey;
        $this->newKey = $newKey;
        $this->removalVersion = $removalVersion;
        $this->kind = $kind;
    }

    public function getJob(): string
    {
        return $this->job;
    }

    public function getOldKey(): string
    {
        return $this->oldKey;
    }

    public function getNewKey(): string
    {
        return $this->newKey;
    }

    public function getRemovalVersion(): string
    {
        return $this->removalVersion;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * Canonical user-facing warning text emitted alongside the structured record.
     * Stable: any change here is a contract break for consumers parsing warnings.
     */
    public function getWarningMessage(): string
    {
        return "Deprecated: '{$this->oldKey}' is renamed to '{$this->newKey}'. Will be removed in {$this->removalVersion}.";
    }

    /**
     * @return array{job: string, oldKey: string, newKey: string, removalVersion: string, kind: string}
     */
    public function toArray(): array
    {
        return [
            'job'            => $this->job,
            'oldKey'         => $this->oldKey,
            'newKey'         => $this->newKey,
            'removalVersion' => $this->removalVersion,
            'kind'           => $this->kind,
        ];
    }
}
