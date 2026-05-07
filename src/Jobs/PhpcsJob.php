<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Execution\ThreadCapability;

class PhpcsJob extends JobAbstract
{
    public const SUPPORTS_FAST = true;

    protected const ARGUMENT_MAP = [
        'standard'         => ['flag' => '--standard', 'type' => 'value'],
        'ignore'           => ['flag' => '--ignore', 'type' => 'csv'],
        'error-severity'   => ['flag' => '--error-severity', 'type' => 'value'],
        'warning-severity' => ['flag' => '--warning-severity', 'type' => 'value'],
        'cache'            => ['flag' => '--cache', 'type' => 'boolean'],
        'no-cache'         => ['flag' => '--no-cache', 'type' => 'boolean'],
        'report'           => ['flag' => '--report', 'type' => 'value'],
        'parallel'         => ['flag' => '--parallel', 'type' => 'value'],
        'paths'            => ['type' => 'paths'],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'phpcs';
    }

    public function supportsStructuredOutput(): bool
    {
        return true;
    }

    public function applyStructuredOutputFormat(): bool
    {
        $this->args['report'] = 'json';
        return true;
    }

    /** @return string[] */
    public function getCachePaths(): array
    {
        $cacheArg = $this->args['cache'] ?? null;
        if (is_string($cacheArg) && trim($cacheArg) !== '') {
            return [trim($cacheArg)];
        }
        $standard = $this->args['standard'] ?? '';
        if (is_string($standard) && $standard !== '') {
            $fromRuleset = $this->extractCacheFromRuleset($standard);
            if ($fromRuleset !== null) {
                return [$fromRuleset];
            }
        }
        return ['.phpcs.cache'];
    }

    private function extractCacheFromRuleset(string $rulesetPath): ?string
    {
        if (!is_file($rulesetPath) || !is_readable($rulesetPath)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($rulesetPath);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return null;
        }
        $resolved = null;
        foreach ($xml->arg ?? [] as $arg) {
            if ((string) $arg['name'] !== 'cache') {
                continue;
            }
            $value = trim((string) $arg['value']);
            if ($value !== '') {
                // Last-wins: a later <arg> overrides an earlier one, matching
                // how phpcs itself processes the ruleset.
                $resolved = $value;
            }
        }
        return $resolved;
    }

    public function getThreadCapability(): ?ThreadCapability
    {
        $current = isset($this->args['parallel']) ? (int) $this->args['parallel'] : 1;
        return new ThreadCapability('parallel', $current);
    }

    public function applyThreadLimit(int $threads): void
    {
        $this->args['parallel'] = $threads;
    }
}
